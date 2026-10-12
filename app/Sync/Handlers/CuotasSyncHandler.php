<?php

namespace App\Sync\Handlers;

use App\Cobradores\DbAssignments;
use App\Comunidad\AjustesService;
use App\Cuotas\CuotasPublisher;
use App\Cuotas\CuotasService;
use App\Exceptions\ApiException;
use App\Models\Cargo;
use App\Models\Permissions;
use App\Models\SyncChange;
use App\Models\User;
use App\Movements\VoidsMovements;
use App\Sync\Mutation;
use App\Sync\RestrictsRecords;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Cuotas y cargos hechos en el escritorio, quizá sin conexión (M5).
 *
 * - cuotas (gana el servidor): {amount_cents, valid_from, note, base_updated_at}.
 * - periodos: create o update = "generar los cargos del mes" {year, month}. El id debe ser
 *   el que se deriva de la comunidad y el mes (CuotasService::periodoId).
 * - cargos: los de cuota los crea la generación del periodo; las multas se crean con
 *   {concept: "multa", familia_id, description, amount_cents, year, month}. Todos se anulan
 *   (op void, {void_reason}).
 *
 * Cuotas y meses los descarga quien los gestiona; el cobrador solo los cargos de sus viviendas.
 */
class CuotasSyncHandler implements RestrictsRecords, RestrictsVisibility, SyncHandler
{
    use VoidsMovements;

    public function __construct(
        private readonly string $entity,
        private readonly CuotasService $cuotas,
        private readonly CuotasPublisher $publisher,
        private readonly DbAssignments $assignments,
    ) {}

    public function policy(): SyncPolicy
    {
        return $this->entity === Cargo::ENTITY ? SyncPolicy::AppendOnly : SyncPolicy::ServerWins;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::PADRON_GESTIONAR)
            || ($this->entity === Cargo::ENTITY && ($user->can(Permissions::COBROS_REGISTRAR) || $user->can(Permissions::COBROS_REGISTRAR_ASIGNADAS)));
    }

    /** El cobrador recibe los cargos de las familias de sus viviendas (para cobrar en M7). */
    public function visibleIdsFor(User $user): ?array
    {
        return $user->can(Permissions::PADRON_GESTIONAR) || $user->can(Permissions::COBROS_REGISTRAR)
            ? null : $this->assignments->visibleIds($user, $this->entity);
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        if ($this->entity === Cargo::ENTITY) {
            if ($mutation->op === Mutation::OP_CREATE && ($mutation->payload['concept'] ?? null) === Cargo::MULTA) {
                return $this->applyMulta($mutation);
            }
            if ($mutation->op !== Mutation::OP_VOID) {
                throw new SyncRejected('not_supported', 'Los cargos de cuota se generan desde el mes; si uno está mal, anúlelo con un motivo.');
            }

            return new SyncApplied(CuotasService::present(Cargo::findOrFail($this->voidFromSync($mutation, Cargo::class)['id'])));
        }

        $actor = User::find($mutation->userId);
        if ($actor === null || ! $actor->can(Permissions::PADRON_GESTIONAR)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para gestionar cuotas.');
        }

        $this->publisher->originDeviceId = $mutation->deviceId;
        try {
            return $this->entity === CuotasService::CUOTAS ? $this->applyCuota($actor, $mutation) : $this->applyPeriodo($actor, $mutation);
        } catch (ValidationException $e) {
            throw new SyncRejected('validation_error', collect($e->errors())->flatten()->implode(' '));
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        } finally {
            $this->publisher->originDeviceId = null;
        }
    }

    private function applyMulta(Mutation $m): SyncApplied
    {
        $actor = User::find($m->userId);
        if ($actor === null || ! $actor->can(Permissions::PADRON_GESTIONAR)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para asignar multas.');
        }

        try {
            $cargo = $this->cuotas->createMulta($actor, (string) ($m->payload['familia_id'] ?? ''),
                array_intersect_key($m->payload, array_flip(['description', 'amount_cents', 'year', 'month'])), $m->entityId);
        } catch (ValidationException $e) {
            throw new SyncRejected('validation_error', collect($e->errors())->flatten()->implode(' '));
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        }

        return new SyncApplied(CuotasService::present($cargo->fresh()));
    }

    private function applyCuota(User $actor, Mutation $m): SyncApplied
    {
        $applied = $this->applyCuotaChange($actor, $m);
        // Los equipos de los cobradores calculan los adelantos con los montos de la cuota.
        app(AjustesService::class)->publish();

        return $applied;
    }

    private function applyCuotaChange(User $actor, Mutation $m): SyncApplied
    {
        $fields = array_intersect_key($m->payload, array_flip(['amount_cents', 'valid_from', 'note']));

        if ($m->op === Mutation::OP_CREATE) {
            return new SyncApplied(CuotasService::present($this->cuotas->createCuota($actor, $fields, $m->entityId)->fresh()));
        }
        if ($m->op !== Mutation::OP_UPDATE) {
            throw new SyncRejected('not_supported', 'Las cuotas no se borran; registre una nueva a partir de otro mes.');
        }

        $cuota = $this->cuotas->findCuota($m->entityId);
        $base = $m->payload['base_updated_at'] ?? null;
        if ($base !== null && $cuota->updated_at->gt(CarbonImmutable::parse($base)->addSecond())) {
            $last = SyncChange::where('entity', CuotasService::CUOTAS)->where('entity_id', $cuota->id)->latest('id')->first();
            if ($last?->origin_device_id !== $m->deviceId) {
                throw new SyncRejected('conflict', 'Otro equipo modificó esta cuota antes; se conservó la versión del servidor.');
            }
        }

        return new SyncApplied(CuotasService::present($this->cuotas->updateCuota($actor, $cuota, $fields)->fresh()));
    }

    private function applyPeriodo(User $actor, Mutation $m): SyncApplied
    {
        if (! in_array($m->op, [Mutation::OP_CREATE, Mutation::OP_UPDATE], true)) {
            throw new SyncRejected('not_supported', 'Los meses generados no se borran; anule los cargos que estén mal.');
        }

        $year = (int) ($m->payload['year'] ?? 0);
        $month = (int) ($m->payload['month'] ?? 0);
        if ($m->entityId !== CuotasService::periodoId($actor->community_id, $year, $month)) {
            throw new SyncRejected('invalid_id', 'El identificador del mes no corresponde a esta comunidad.');
        }

        $result = $this->cuotas->generate($actor, $year, $month);

        return new SyncApplied(
            CuotasService::present($result['periodo']->fresh()),
            [['code' => 'cargos_generated', 'message' => CuotasService::generatedNotice($result)]],
        );
    }
}
