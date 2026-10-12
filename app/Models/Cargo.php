<?php

namespace App\Models;

use App\Movements\Voidable;
use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que una familia debe: la cuota de un mes o una multa. Es un movimiento: no se edita ni se
 * borra; si se generó por error, se anula con motivo.
 */
class Cargo extends Model
{
    use BelongsToCommunity, HasUuids, Voidable;

    public const ENTITY = 'cargos';

    /** La cuota del mes (pertenece a un periodo). */
    public const CUOTA = 'cuota';

    /** Multa con su motivo, asignada a una familia en un mes. */
    public const MULTA = 'multa';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount_cents' => 'integer',
            'meses' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function movementEntity(): string
    {
        return self::ENTITY;
    }

    public function familia(): BelongsTo
    {
        return $this->belongsTo(Familia::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }
}
