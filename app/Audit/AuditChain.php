<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Tenancy\CurrentCommunity;
use Illuminate\Support\Facades\DB;

/**
 * Cadena de huellas de la bitácora: cada registro guarda el SHA-256 de su contenido junto
 * con la huella del registro anterior de su comunidad. Alterar, quitar o reordenar un
 * registro rompe la cadena desde ese punto (ver verify()).
 */
final class AuditChain
{
    /** Huella de un registro a partir de sus datos y la huella anterior. */
    public static function hash(AuditLog $log, ?string $previous): string
    {
        $content = [
            'community_id' => $log->community_id,
            'user_id' => $log->user_id,
            'device_id' => $log->device_id,
            'action' => $log->action,
            'entity' => $log->entity,
            'entity_id' => $log->entity_id,
            'data' => self::canonical($log->data),
            'created_at' => $log->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'prev_hash' => $previous,
        ];

        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Última huella de la comunidad. En PostgreSQL toma un candado de la transacción para que
     * dos registros simultáneos no compartan el mismo anterior.
     */
    public static function lastHash(?string $communityId): ?string
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(?)', [crc32('audit:'.($communityId ?? 'system'))]);
        }

        return app(CurrentCommunity::class)->runAsSystem(fn () => AuditLog::query()
            ->when($communityId === null, fn ($q) => $q->whereNull('community_id'), fn ($q) => $q->where('community_id', $communityId))
            ->orderByDesc('id')
            ->value('hash'));
    }

    /**
     * Recorre la cadena de una comunidad (null = eventos del sistema).
     *
     * @return array{ok: bool, checked: int, broken_at: ?int}
     */
    public static function verify(?string $communityId): array
    {
        $previous = null;
        $checked = 0;

        $query = app(CurrentCommunity::class)->runAsSystem(fn () => AuditLog::query()
            ->when($communityId === null, fn ($q) => $q->whereNull('community_id'), fn ($q) => $q->where('community_id', $communityId))
            ->orderBy('id')
            ->get());

        foreach ($query as $log) {
            $checked++;
            if ($log->prev_hash !== $previous || $log->hash !== self::hash($log, $previous)) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $log->id];
            }
            $previous = $log->hash;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }

    /** Ordena las llaves para que la huella no dependa del orden en que se guardaron. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
