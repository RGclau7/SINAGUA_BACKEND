<?php

namespace App\Sync\Handlers;

use App\Comunidad\AjustesService;
use App\Models\Permissions;
use App\Models\User;
use App\Sync\Mutation;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de cobro de la comunidad. Todos los usuarios de la comunidad los descargan; solo el
 * administrador los cambia: update {multa_cents, multa_dia_limite, meses_adelanto}.
 */
class AjustesSyncHandler implements SyncHandler
{
    public function __construct(private readonly AjustesService $ajustes) {}

    public function policy(): SyncPolicy
    {
        return SyncPolicy::ServerWins;
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        $actor = User::find($mutation->userId);
        if ($actor === null || ! $actor->can(Permissions::PADRON_GESTIONAR)) {
            throw new SyncRejected('forbidden', 'Solo el administrador cambia los ajustes de cobro.');
        }
        if ($mutation->op !== Mutation::OP_UPDATE || $mutation->entityId !== $actor->community_id) {
            throw new SyncRejected('not_supported', 'Los ajustes solo se modifican.');
        }

        $this->ajustes->originDeviceId = $mutation->deviceId;
        try {
            return new SyncApplied(AjustesService::present($this->ajustes->update($actor, $mutation->payload)));
        } catch (ValidationException $e) {
            throw new SyncRejected('validation_error', collect($e->errors())->flatten()->implode(' '));
        } finally {
            $this->ajustes->originDeviceId = null;
        }
    }
}
