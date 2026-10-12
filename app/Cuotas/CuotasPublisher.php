<?php

namespace App\Cuotas;

use App\Comunidad\AjustesService;
use App\Models\Cargo;
use App\Models\Cuota;
use App\Models\Periodo;
use App\Models\SyncChange;
use Illuminate\Database\Eloquent\Model;

/**
 * Publica en el feed de sincronización cuotas, periodos y cargos cambiados fuera de
 * /sync/push o como efecto de otra operación (generar un mes crea muchos cargos).
 */
class CuotasPublisher
{
    /** Equipo cuya mutación provocó el cambio (lo fija la sincronización mientras aplica una). */
    public ?string $originDeviceId = null;

    public function publish(Model $record, string $op): void
    {
        (new SyncChange)->forceFill([
            'community_id' => $record->community_id,
            'entity' => self::entityOf($record),
            'entity_id' => $record->id,
            'op' => $op,
            'payload' => CuotasService::present($record->fresh()),
            'origin_device_id' => $this->originDeviceId,
            'created_at' => now(),
        ])->save();

        // Los equipos de los cobradores calculan los adelantos con los montos de la cuota.
        if ($record instanceof Cuota) {
            app(AjustesService::class)->publish();
        }
    }

    public static function entityOf(Model $record): string
    {
        return match (true) {
            $record instanceof Cuota => CuotasService::CUOTAS,
            $record instanceof Periodo => CuotasService::PERIODOS,
            $record instanceof Cargo => Cargo::ENTITY,
        };
    }
}
