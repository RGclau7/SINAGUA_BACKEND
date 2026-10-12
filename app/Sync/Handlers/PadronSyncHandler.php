<?php

namespace App\Sync\Handlers;

use App\Cobradores\DbAssignments;
use App\Exceptions\ApiException;
use App\Models\Permissions;
use App\Models\SyncChange;
use App\Models\User;
use App\Padron\PadronPublisher;
use App\Padron\PadronService;
use App\Sync\Mutation;
use App\Sync\RestrictsRecords;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Padrón hecho en el escritorio, quizá sin conexión: viviendas, familias y habitantes.
 * Catálogo: gana el servidor. Una edición basada en una versión vieja se rechaza.
 *
 * Payload: los campos del registro (ver PadronService::present) más is_active,
 * deactivation_reason, confirm_debt (la persona aceptó dar de baja con adeudo) y base_updated_at.
 * Nunca se borra: la baja es is_active = false.
 *
 * Lo descarga completo quien gestiona el padrón o cobra a todos (administrador y cobradores).
 */
class PadronSyncHandler implements RestrictsRecords, RestrictsVisibility, SyncHandler
{
    private const FIELDS = [
        PadronService::VIVIENDAS => ['address', 'neighborhood', 'reference'],
        PadronService::FAMILIAS => ['vivienda_id', 'name', 'head_id', 'cuota_especial_cents'],
        PadronService::HABITANTES => ['familia_id', 'given_names', 'paternal_surname', 'maternal_surname', 'phone'],
    ];

    public function __construct(
        private readonly string $entity,
        private readonly PadronService $padron,
        private readonly PadronPublisher $publisher,
        private readonly DbAssignments $assignments,
    ) {}

    public function policy(): SyncPolicy
    {
        return SyncPolicy::ServerWins;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::PADRON_GESTIONAR) || $user->can(Permissions::PADRON_CONSULTAR_ASIGNADAS);
    }

    /** El cobrador solo descarga sus viviendas asignadas y lo que cuelga de ellas (M6). */
    public function visibleIdsFor(User $user): ?array
    {
        return $user->can(Permissions::PADRON_GESTIONAR) || $user->can(Permissions::COBROS_REGISTRAR)
            ? null : $this->assignments->visibleIds($user, $this->entity);
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        $actor = User::find($mutation->userId);
        if ($actor === null || ! $actor->can(Permissions::PADRON_GESTIONAR)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para modificar el padrón.');
        }

        $this->publisher->originDeviceId = $mutation->deviceId;
        try {
            return match ($mutation->op) {
                Mutation::OP_CREATE => $this->create($actor, $mutation),
                Mutation::OP_UPDATE => $this->update($actor, $mutation),
                default => throw new SyncRejected('not_supported', 'Los registros del padrón no se borran; se dan de baja.'),
            };
        } catch (ValidationException $e) {
            throw new SyncRejected('validation_error', collect($e->errors())->flatten()->implode(' '));
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        } finally {
            $this->publisher->originDeviceId = null;
        }
    }

    private function create(User $actor, Mutation $m): SyncApplied
    {
        $fields = $this->fields($m->payload);
        $record = match ($this->entity) {
            PadronService::VIVIENDAS => $this->padron->createVivienda($actor, $fields, $m->entityId),
            PadronService::FAMILIAS => $this->padron->createFamilia($actor, $fields, $m->entityId),
            PadronService::HABITANTES => $this->padron->createHabitante($actor, $fields, $m->entityId),
        };

        return new SyncApplied(PadronService::present($record->fresh()));
    }

    private function update(User $actor, Mutation $m): SyncApplied
    {
        $record = $this->padron->find($this->entity, $m->entityId);

        if ($this->changedElsewhereSince($m, $record)) {
            throw new SyncRejected('conflict', 'Otro equipo modificó este registro antes; se conservó la versión del servidor.');
        }

        $notices = [];
        $wantsActive = array_key_exists('is_active', $m->payload) ? (bool) $m->payload['is_active'] : $record->is_active;

        // Se reactiva antes de editar y se da de baja después, así las reglas de "padre activo" se cumplen.
        if ($wantsActive && ! $record->is_active) {
            $this->padron->activate($actor, $record);
        }

        $fields = $this->fields($m->payload);
        $record = match ($this->entity) {
            PadronService::VIVIENDAS => $this->padron->updateVivienda($actor, $record, $fields),
            PadronService::FAMILIAS => $this->padron->updateFamilia($actor, $record, $fields),
            PadronService::HABITANTES => $this->padron->updateHabitante($actor, $record, $fields),
        };

        if (! $wantsActive && $record->is_active) {
            $notices = $this->padron->deactivate($actor, $record, $m->payload['deactivation_reason'] ?? null, (bool) ($m->payload['confirm_debt'] ?? false));
        }

        return new SyncApplied(PadronService::present($record->fresh()), $notices);
    }

    /**
     * Conflicto si el servidor tiene una versión más nueva que la que vio el equipo y ese
     * último cambio no vino del mismo equipo (sus ediciones encadenadas sin red no chocan).
     */
    private function changedElsewhereSince(Mutation $m, Model $record): bool
    {
        $base = $m->payload['base_updated_at'] ?? null;
        if ($base === null || ! $record->updated_at->gt(CarbonImmutable::parse($base)->addSecond())) {
            return false;
        }

        $lastChange = SyncChange::where('entity', $this->entity)
            ->where('entity_id', $record->id)
            ->latest('id')
            ->first();

        return $lastChange?->origin_device_id !== $m->deviceId;
    }

    private function fields(array $payload): array
    {
        return array_intersect_key($payload, array_flip(self::FIELDS[$this->entity]));
    }
}
