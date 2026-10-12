<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cobranza (M7): un pago con folio por familia, que liquida completos uno o varios cargos
     * (cuotas de meses o multas). No hay pagos parciales. El pago es un movimiento: no se edita
     * ni se borra; si está mal, se anula con motivo y sus cargos vuelven a quedar por pagar.
     */
    public const TABLES = ['pagos', 'pago_detalles'];

    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('familia_id')->constrained('familias');
            $table->foreignUuid('vivienda_id')->constrained('viviendas');
            $table->foreignUuid('cobrador_id')->constrained('users'); // quien recibió el dinero
            $table->uuid('device_id')->nullable();
            $table->string('folio', 20);
            $table->unsignedInteger('total_cents');
            $table->timestamp('paid_at');
            $table->voidable();
            $table->timestamps();
            $table->unique(['community_id', 'folio']);
            $table->index(['community_id', 'familia_id']);
            $table->index(['community_id', 'cobrador_id', 'paid_at']);
        });

        Schema::create('pago_detalles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('pago_id')->constrained('pagos');
            $table->foreignUuid('cargo_id')->constrained('cargos');
            $table->unsignedInteger('amount_cents');
            $table->timestamps();
            $table->unique(['pago_id', 'cargo_id']);
            $table->index(['community_id', 'cargo_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY {$table}_community ON {$table} USING (app_can_access(community_id)) WITH CHECK (app_can_access(community_id))");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::statement("DROP POLICY IF EXISTS {$table}_community ON {$table}");
            }
        }
        Schema::dropIfExists('pago_detalles');
        Schema::dropIfExists('pagos');
    }
};
