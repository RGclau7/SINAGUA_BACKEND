<?php

namespace App\Models;

use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuota mensual por familia, vigente desde un mes. La vigente para un mes es la de fecha
 * más reciente que no sea posterior a ese mes.
 */
#[Fillable(['amount_cents', 'valid_from', 'note'])]
class Cuota extends Model
{
    use BelongsToCommunity, HasUuids;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'valid_from' => 'date',
        ];
    }

    /** Cuota vigente en el mes indicado, o null si aún no había ninguna. */
    public static function forMonth(int $year, int $month): ?self
    {
        return static::whereDate('valid_from', '<=', sprintf('%04d-%02d-01', $year, $month))
            ->orderByDesc('valid_from')
            ->first();
    }
}
