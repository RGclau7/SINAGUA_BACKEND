<?php

namespace App\Models;

use App\Audit\AuditChain;
use App\Audit\AuditFeed;
use App\Audit\AuditSanitizer;
use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

#[Fillable(['user_id', 'device_id', 'action', 'entity', 'entity_id', 'data', 'created_at'])]
class AuditLog extends Model
{
    use BelongsToCommunity;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function communityIsOptional(): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        // Después de que BelongsToCommunity asignó la comunidad: limpia secretos y encadena.
        static::creating(function (AuditLog $log) {
            $log->created_at ??= now();
            $log->data = AuditSanitizer::clean($log->data);
            $log->prev_hash = AuditChain::lastHash($log->community_id);
            $log->hash = AuditChain::hash($log, $log->prev_hash);
        });
        static::created(fn (AuditLog $log) => app(AuditFeed::class)->publish($log));

        static::updating(fn () => throw new LogicException('La bitácora es inmutable.'));
        static::deleting(fn () => throw new LogicException('La bitácora es inmutable.'));
    }

    /**
     * La comunidad se toma del contexto actual. Solo en modo sistema (superadministrador)
     * puede indicarse community_id explícitamente.
     */
    public static function record(string $action, array $attributes = []): self
    {
        // En una transacción para que el candado de la cadena dure hasta guardar el registro.
        return DB::transaction(function () use ($action, $attributes) {
            $log = (new static)->forceFill(['action' => $action, 'created_at' => now(), ...$attributes]);
            $log->save();

            return $log;
        });
    }
}
