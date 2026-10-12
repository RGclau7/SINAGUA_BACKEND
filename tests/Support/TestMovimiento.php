<?php

namespace Tests\Support;

use App\Movements\Voidable;
use App\Tenancy\BelongsToCommunity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimiento de ejemplo para probar la anulación hasta que existan pagos, gastos, etc.
 */
class TestMovimiento extends Model
{
    use BelongsToCommunity, HasUuids, Voidable;

    protected $table = 'test_movimientos';

    protected $fillable = ['id', 'concepto', 'monto'];

    protected function casts(): array
    {
        return ['voided_at' => 'datetime', 'monto' => 'decimal:2'];
    }

    public function movementEntity(): string
    {
        return 'pruebas';
    }

    public static function createTable(): void
    {
        Schema::create('test_movimientos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id');
            $table->string('concepto');
            $table->decimal('monto', 12, 2);
            $table->voidable();
            $table->timestamps();
        });
    }
}
