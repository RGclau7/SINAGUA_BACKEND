<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vivienda asignada a un cobrador desde starts_on; vigente mientras ends_on sea null.
 */
class Asignacion extends Model
{
    use BelongsToCommunity, HasUuids;

    public const ENTITY = 'asignaciones';

    protected $table = 'asignaciones';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @param Builder<Asignacion> $query */
    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('ends_on');
    }

    public function cobrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cobrador_id');
    }

    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class);
    }
}
