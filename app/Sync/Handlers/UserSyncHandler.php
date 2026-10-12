<?php

namespace App\Sync\Handlers;

use App\Comunidad\AjustesService;
use App\Exceptions\ApiException;
use App\Models\Permissions;
use App\Models\SyncChange;
use App\Models\User;
use App\Sync\Mutation;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;
use App\Users\UserChangePublisher;
use App\Users\UserService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Altas y cambios de usuarios hechos en el escritorio, quizá sin conexión.
 * Catálogo: gana el servidor. Una edición basada en una versión vieja se rechaza.
 *
 * Payload: {given_names, paternal_surname, maternal_surname?, phone, address, email, role,
 *           is_active, password_hash?, base_updated_at?}
 * Nunca se borra: desactivar es is_active = false.
 */
class UserSyncHandler implements RestrictsVisibility, SyncHandler
{
    public function __construct(private readonly UserService $users) {}

    public function policy(): SyncPolicy
    {
        return SyncPolicy::ServerWins;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::USUARIOS_GESTIONAR);
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        $actor = User::find($mutation->userId);
        if ($actor === null || ! $this->visibleTo($actor)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para gestionar usuarios.');
        }

        try {
            $applied = match ($mutation->op) {
                Mutation::OP_CREATE => $this->create($actor, $mutation),
                Mutation::OP_UPDATE => $this->update($actor, $mutation),
                default => throw new SyncRejected('not_supported', 'Los usuarios no se eliminan; desactívelos.'),
            };
            // Los recibos llevan los nombres de los cobradores vigentes.
            app(AjustesService::class)->publish();

            return $applied;
        } catch (ValidationException $e) {
            throw new SyncRejected('validation_error', collect($e->errors())->flatten()->implode(' '));
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        }
    }

    private function create(User $actor, Mutation $m): SyncApplied
    {
        $user = $this->users->create($actor, $this->fields($m->payload), $m->entityId);

        if (($m->payload['is_active'] ?? true) === false) {
            $this->users->deactivate($actor, $user);
        }

        return new SyncApplied(UserService::present($user->fresh()));
    }

    private function update(User $actor, Mutation $m): SyncApplied
    {
        $user = $this->users->find($m->entityId);

        if ($this->changedElsewhereSince($m, $user)) {
            throw new SyncRejected('conflict', 'Otro equipo modificó este usuario antes; se conservó la versión del servidor.');
        }

        $this->users->update($actor, $user, $this->fields($m->payload));

        $notices = [];
        if (array_key_exists('is_active', $m->payload) && (bool) $m->payload['is_active'] !== $user->is_active) {
            if ($m->payload['is_active']) {
                $this->users->activate($actor, $user);
            } else {
                $notices = $this->users->deactivate($actor, $user);
            }
        }

        return new SyncApplied(UserService::present($user->fresh()), $notices);
    }

    /**
     * Conflicto si el servidor tiene una versión más nueva que la que vio el equipo y ese
     * último cambio no vino del mismo equipo (sus ediciones encadenadas sin red no chocan).
     */
    private function changedElsewhereSince(Mutation $m, User $user): bool
    {
        $base = $m->payload['base_updated_at'] ?? null;
        if ($base === null || ! $user->updated_at->gt(CarbonImmutable::parse($base)->addSecond())) {
            return false;
        }

        $lastChange = SyncChange::where('entity', UserChangePublisher::ENTITY)
            ->where('entity_id', $user->id)
            ->latest('id')
            ->first();

        return $lastChange?->origin_device_id !== $m->deviceId;
    }

    private function fields(array $payload): array
    {
        return array_intersect_key($payload, array_flip(['given_names', 'paternal_surname', 'maternal_surname', 'phone', 'address', 'email', 'role', 'password_hash']));
    }
}
