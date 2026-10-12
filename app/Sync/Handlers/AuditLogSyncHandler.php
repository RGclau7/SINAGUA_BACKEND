<?php

namespace App\Sync\Handlers;

use App\Models\Permissions;
use App\Models\User;
use App\Sync\Mutation;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;

/**
 * La bitácora solo baja a los equipos (y solo a quien puede consultarla); ningún equipo la escribe.
 */
class AuditLogSyncHandler implements RestrictsVisibility, SyncHandler
{
    public function policy(): SyncPolicy
    {
        return SyncPolicy::AppendOnly;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::BITACORA_CONSULTAR);
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        throw new SyncRejected('read_only', 'La bitácora es de solo lectura.');
    }
}
