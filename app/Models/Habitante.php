<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona de la comunidad, integrante de una familia. El teléfono es opcional.
 */
#[Fillable(['given_names', 'paternal_surname', 'maternal_surname', 'phone'])]
class Habitante extends Model
{
    use BelongsToCommunity, HasUuids;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    public function familia(): BelongsTo
    {
        return $this->belongsTo(Familia::class);
    }
}
