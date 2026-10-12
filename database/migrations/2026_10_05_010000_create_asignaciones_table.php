<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asignación de viviendas a cobradores (M6) con vigencia: una vivienda tiene a lo más un
     * cobrador a la vez; al reasignarla, la asignación anterior se cierra (ends_on) y se conserva.
     */
    public function up(): void
    {
        Schema::create('asignaciones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('cobrador_id')->constrained('users');
            $table->foreignUuid('vivienda_id')->constrained('viviendas');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['community_id', 'cobrador_id', 'ends_on']);
            $table->index(['community_id', 'vivienda_id', 'ends_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Una sola asignación vigente por vivienda.
            DB::statement('CREATE UNIQUE INDEX asignaciones_vivienda_vigente ON asignaciones (community_id, vivienda_id) WHERE ends_on IS NULL');
            DB::statement('ALTER TABLE asignaciones ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE asignaciones FORCE ROW LEVEL SECURITY');
            DB::statement('CREATE POLICY asignaciones_community ON asignaciones USING (app_can_access(community_id)) WITH CHECK (app_can_access(community_id))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS asignaciones_community ON asignaciones');
        }
        Schema::dropIfExists('asignaciones');
    }
};
