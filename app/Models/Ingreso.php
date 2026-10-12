<?php

namespace App\Models;

use App\Movements\Voidable;
use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dinero que entra a un fondo de ingresos extras (M8). Movimiento: solo se anula con motivo.
 */
class Ingreso extends Model
{
    use BelongsToCommunity, HasUuids, Voidable;

    public const ENTITY = 'ingresos';

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'received_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function movementEntity(): string
    {
        return self::ENTITY;
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaIngreso::class, 'categoria_id');
    }
}
