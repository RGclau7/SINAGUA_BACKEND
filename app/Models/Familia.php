<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Familia que vive en una vivienda; a ella se le cobra la cuota (M5).
 * head_id es el titular: el habitante que responde por la cuota.
 */
#[Fillable(['name'])]
class Familia extends Model
{
    use BelongsToCommunity, HasUuids;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
            'cuota_especial_cents' => 'integer',
        ];
    }

    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Vivienda::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Habitante::class, 'head_id');
    }

    public function habitantes(): HasMany
    {
        return $this->hasMany(Habitante::class);
    }
}
