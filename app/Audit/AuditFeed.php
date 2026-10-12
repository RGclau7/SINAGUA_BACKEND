<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\SyncChange;
use App\Models\User;

/**
 * Publica cada registro de la bitácora en el feed de sincronización (entidad "bitacora"),
 * para que el administrador la consulte en su equipo aun sin conexión. Solo la descargan
 * quienes tienen bitacora.consultar (ver AuditLogSyncHandler).
 */
class AuditFeed
{
    public const ENTITY = 'bitacora';

    public function publish(AuditLog $log): void
    {
        // Los eventos del sistema (sin comunidad) no van a ningún equipo.
        if ($log->community_id === null) {
            return;
        }

        (new SyncChange)->forceFill([
            'community_id' => $log->community_id,
            'entity' => self::ENTITY,
            'entity_id' => $this->entityId($log),
            'op' => 'create',
            'payload' => self::present($log),
            'created_at' => now(),
        ])->save();
    }

    public static function present(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'action' => $log->action,
            'label' => AuditLabels::label($log->action),
            'category' => AuditLabels::category($log->action),
            'entity' => $log->entity,
            'entity_id' => $log->entity_id,
            'user_id' => $log->user_id,
            'user_name' => $log->user_id ? User::whereKey($log->user_id)->value('name') : null,
            'device_id' => $log->device_id,
            'data' => $log->data,
            'hash' => $log->hash,
        ];
    }

    /** UUID estable por registro (el feed indexa por UUID). */
    private function entityId(AuditLog $log): string
    {
        $hex = substr(hash('sha256', 'bitacora:'.$log->id), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
