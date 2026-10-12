<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajustes de cobro de cada comunidad, cuota especial por titular y multas automáticas.
     */
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            // Multa fija por cada mes no pagado a tiempo (0 = sin multa).
            $table->unsignedInteger('multa_cents')->default(0);
            // El mes se paga a más tardar este día del mes siguiente.
            $table->unsignedTinyInteger('multa_dia_limite')->default(10);
            // Meses que se pueden pagar por adelantado.
            $table->unsignedTinyInteger('meses_adelanto')->default(3);
        });

        Schema::table('familias', function (Blueprint $table) {
            // Lo que paga este titular al mes si es distinto de la cuota normal (casas que pagan más).
            $table->unsignedInteger('cuota_especial_cents')->nullable();
        });

        Schema::table('cargos', function (Blueprint $table) {
            // En una multa automática, la cuota del mes que no se pagó a tiempo.
            $table->uuid('origen_cargo_id')->nullable();
            $table->index(['community_id', 'origen_cargo_id']);
        });

        Schema::table('pagos', function (Blueprint $table) {
            // Cobradores que había cuando se emitió el recibo (aparecen impresos en él).
            $table->string('cobradores', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pagos', fn (Blueprint $t) => $t->dropColumn('cobradores'));
        Schema::table('cargos', function (Blueprint $t) {
            $t->dropIndex(['community_id', 'origen_cargo_id']);
            $t->dropColumn('origen_cargo_id');
        });
        Schema::table('familias', fn (Blueprint $t) => $t->dropColumn('cuota_especial_cents'));
        Schema::table('communities', fn (Blueprint $t) => $t->dropColumn(['multa_cents', 'multa_dia_limite', 'meses_adelanto']));
    }
};
