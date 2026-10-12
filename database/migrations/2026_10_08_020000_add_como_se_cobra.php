<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Cómo se cobra": cada cuántos meses se cobra, qué días del mes se recibe el pago y si se
     * puede cobrar fuera de esos días. Cada periodo y cargo guarda cuántos meses cubre.
     */
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->unsignedTinyInteger('periodicidad_meses')->default(1);
            $table->string('dias_pago', 100)->default('1,2,3,4,5,6,7,8,9,10');
            $table->boolean('cobro_fuera_de_dias')->default(true);
        });
        Schema::table('periodos', fn (Blueprint $t) => $t->unsignedTinyInteger('meses')->default(1));
        Schema::table('cargos', fn (Blueprint $t) => $t->unsignedTinyInteger('meses')->default(1));
    }

    public function down(): void
    {
        Schema::table('cargos', fn (Blueprint $t) => $t->dropColumn('meses'));
        Schema::table('periodos', fn (Blueprint $t) => $t->dropColumn('meses'));
        Schema::table('communities', fn (Blueprint $t) => $t->dropColumn(['periodicidad_meses', 'dias_pago', 'cobro_fuera_de_dias']));
    }
};
