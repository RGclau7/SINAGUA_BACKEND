<?php

namespace App\Models;

use App\Movements\Voidable;
use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pago con folio de una familia: liquida completos uno o varios cargos (M7).
 */
class Pago extends Model
{
    use BelongsToCommunity, HasUuids, Voidable;

    public const ENTITY = 'pagos';

    protected function casts(): array
    {
        return [
            'total_cents' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function movementEntity(): string
    {
        return self::ENTITY;
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(PagoDetalle::class);
    }

    public function familia(): BelongsTo
    {
        return $this->belongsTo(Familia::class);
    }

    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class);
    }

    public function cobrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cobrador_id');
    }
}
