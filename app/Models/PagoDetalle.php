<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un cargo (cuota de un mes o multa) liquidado completo por un pago.
 */
class PagoDetalle extends Model
{
    use BelongsToCommunity, HasUuids;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El detalle de un pago no se modifica.'));
        static::deleting(fn () => throw new LogicException('El detalle de un pago no se borra.'));
    }

    public function cargo(): BelongsTo
    {
        return $this->belongsTo(Cargo::class);
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(Pago::class);
    }
}
