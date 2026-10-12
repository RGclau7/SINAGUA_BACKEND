<?php

namespace App\Models;

use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'state', 'municipality', 'locality', 'contact_phone', 'contact_email', 'is_active'])]
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory, HasUuids;

    /** Igual que el default de la columna, para que la respuesta al crear no traiga null. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'cobro_fuera_de_dias' => 'boolean',
            'periodicidad_meses' => 'integer',
            'multa_cents' => 'integer',
            'meses_adelanto' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
