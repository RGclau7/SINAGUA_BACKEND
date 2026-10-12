<?php

namespace App\Sync\Handlers;

use App\Cobradores\AsignacionesService;
use App\Cobradores\DbAssignments;
use App\Exceptions\ApiException;
use App\Models\Asignacion;
use App\Models\Permissions;
use App\Models\User;
use App\Sync\Mutation;
use App\Sync\RestrictsRecords;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;

/**
 * Asignaciones de viviendas a cobradores hechas en el escritorio (M6).
 * create {cobrador_id, vivienda_id}: asigna desde hoy (cierra la asignación anterior de la vivienda).
 * update {ends_on}: quita la vivienda al cobrador. Nunca se borran.
 * El administrador las descarga todas; el cobrador, solo las suyas.
 */
class AsignacionesSyncHandler implements RestrictsRecords, RestrictsVisibility, SyncHandler
{
    public function __construct(
        private readonly AsignacionesService $service,
        private readonly DbAssignments $assignments,
    ) {}

    public function policy(): SyncPolicy
    {
        return SyncPolicy::ServerWins;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::USUARIOS_GESTIONAR) || $user->can(Permissions::PADRON_CONSULTAR_ASIGNADAS);
    }

    public function visibleIdsFor(User $user): ?array
    {
        return $user->can(Permissions::USUARIOS_GESTIONAR) ? null : $this->assignments->visibleIds($user, Asignacion::ENTITY);
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        $actor = User::find($mutation->userId);
        if ($actor === null || ! $actor->can(Permissions::USUARIOS_GESTIONAR)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para asignar viviendas.');
        }

        $this->service->originDeviceId = $mutation->deviceId;
        try {
            $asignacion = match ($mutation->op) {
                Mutation::OP_CREATE => $this->service->assign(
                    $actor,
                    $this->service->cobrador((string) ($mutation->payload['cobrador_id'] ?? '')),
                    (string) ($mutation->payload['vivienda_id'] ?? ''),
                    $mutation->entityId,
                ),
                Mutation::OP_UPDATE => empty($mutation->payload['ends_on'])
                    ? throw new SyncRejected('not_supported', 'Una asignación solo se puede terminar.')
                    : $this->service->end($actor, $this->service->find($mutation->entityId)),
                default => throw new SyncRejected('not_supported', 'Las asignaciones no se borran; se terminan.'),
            };
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        } finally {
            $this->service->originDeviceId = null;
        }

        return new SyncApplied(AsignacionesService::present($asignacion->fresh()));
    }
}
