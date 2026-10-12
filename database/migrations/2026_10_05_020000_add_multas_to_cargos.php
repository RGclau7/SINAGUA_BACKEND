<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multas: un cargo con concepto "multa" y su motivo, asignado a una familia en un mes,
     * sin periodo (la cuota del mes es concepto "cuota" y sí pertenece a un periodo).
     */
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->string('concept', 20)->default('cuota')->after('familia_id');
            $table->string('description')->nullable()->after('concept');
            $table->uuid('periodo_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->dropColumn(['concept', 'description']);
        });
    }
};
