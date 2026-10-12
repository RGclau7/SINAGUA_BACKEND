<?php

namespace App\Models;

use App\Comunidad\Calendario;
use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mes al que se le generaron cargos. Guarda el monto con que se generó.
 */
class Periodo extends Model
{
    use BelongsToCommunity, HasUuids;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(Cuota::class);
    }

    public function cargos(): HasMany
    {
        return $this->hasMany(Cargo::class);
    }

    public function label(): string
    {
        return Calendario::label($this->year, $this->month, (int) ($this->meses ?? 1));
    }

    /** "octubre 2026" */
    public static function monthLabel(int $year, int $month): string
    {
        $names = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $names[$month - 1].' '.$year;
    }
}
