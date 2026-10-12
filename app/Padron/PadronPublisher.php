<?php

namespace App\Padron;

use App\Models\SyncChange;
use Illuminate\Database\Eloquent\Model;

/**
 * Publica en el feed de sincronización los cambios del padrón hechos fuera de /sync/push
 * (API REST) o como efecto de otra operación (la baja de una vivienda da de baja sus familias),
 * para que los equipos de la comunidad los descarguen.
 */
class PadronPublisher
{
    /**
     * Equipo cuya mutación provocó el cambio (lo fija la sincronización mientras aplica una);
     * así sus siguientes ediciones sin red sobre esos registros no se toman como conflicto.
     */
    public ?string $originDeviceId = null;

    public function publish(Model $record, string $op): void
    {
        (new SyncChange)->forceFill([
            'community_id' => $record->community_id,
            'entity' => PadronService::entityOf($record),
            'entity_id' => $record->id,
            'op' => $op,
            'payload' => PadronService::present($record->fresh()),
            'origin_device_id' => $this->originDeviceId,
            'created_at' => now(),
        ])->save();
    }
}
