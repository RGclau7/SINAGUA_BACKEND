<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuotas y cargos (M5): cuota mensual con vigencia, periodos (meses) y un cargo por familia
     * y periodo. Los montos van en centavos.
     *
     * - Cambiar la cuota es registrar otra con una fecha "vigente desde"; así queda el historial.
     * - Cada periodo guarda el monto con que se generó; un cambio de cuota no altera cargos ya hechos.
     * - El cargo es un movimiento: no se edita ni se borra, solo se anula con motivo.
     */
    public const TABLES = ['cuotas', 'periodos', 'cargos'];

    public function up(): void
    {
        Schema::create('cuotas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->unsignedInteger('amount_cents');
            $table->date('valid_from'); // primer día del mes desde el que aplica
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'valid_from']);
        });

        Schema::create('periodos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignUuid('cuota_id')->constrained('cuotas');
            $table->unsignedInteger('amount_cents');
            $table->timestamps();
            $table->unique(['community_id', 'year', 'month']);
        });

        Schema::create('cargos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('periodo_id')->constrained('periodos');
            $table->foreignUuid('familia_id')->constrained('familias');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('amount_cents');
            $table->voidable();
            $table->timestamps();
            // Una familia tiene un solo cargo por periodo en su comunidad.
            $table->unique(['community_id', 'familia_id', 'periodo_id']);
            $table->index(['community_id', 'familia_id', 'year', 'month']);
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

        Schema::dropIfExists('cargos');
        Schema::dropIfExists('periodos');
        Schema::dropIfExists('cuotas');
    }
};
