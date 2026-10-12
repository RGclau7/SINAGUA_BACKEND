<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de ingreso extra (Baños comunitarios, Renta del salón…). Es un fondo: su saldo es
 * la suma de sus ingresos vigentes (M9 restará los gastos que salgan de él). No se borra; se
 * desactiva para que ya no se use.
 */
class CategoriaIngreso extends Model
{
    use BelongsToCommunity, HasUuids;

    public const ENTITY = 'categorias_ingreso';

    protected $table = 'categorias_ingreso';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function ingresos(): HasMany
    {
        return $this->hasMany(Ingreso::class, 'categoria_id');
    }

    /** Lo que hay en el fondo: ingresos sin anular. */
    public function saldoCents(): int
    {
        return (int) $this->ingresos()->whereNull('voided_at')->sum('amount_cents');
    }
}
