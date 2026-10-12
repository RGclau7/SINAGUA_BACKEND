<?php

namespace App\Movements;

use App\Exceptions\ApiException;
use App\Models\Permissions;
use App\Models\User;
use App\Sync\Mutation;
use App\Sync\SyncRejected;
use Illuminate\Database\Eloquent\Model;

/**
 * Para los SyncHandler de movimientos: procesa la mutación "void" que el escritorio envía al
 * anular sin conexión. Payload: {void_reason}.
 */
trait VoidsMovements
{
    /**
     * @param  class-string<Model>  $model
     */
    protected function voidFromSync(Mutation $mutation, string $model): array
    {
        $actor = User::find($mutation->userId);
        if ($actor === null || ! $actor->can(Permissions::MOVIMIENTOS_ANULAR)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para anular movimientos.');
        }

        $movement = $model::find($mutation->entityId);
        if ($movement === null) {
            throw new SyncRejected('not_found', 'El movimiento no existe en esta comunidad.');
        }

        try {
            $movement->voidWith($actor, $mutation->payload['void_reason'] ?? null, $mutation->deviceId);
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        }

        return $movement->fresh()->toArray();
    }
}
