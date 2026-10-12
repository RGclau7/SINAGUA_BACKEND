<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equipos (instalaciones de la app de escritorio). El UUID lo genera el cliente
     * y el prefijo de folio lo asigna el servidor para que los recibos emitidos
     * sin conexión sean únicos (ej. E01-000123).
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->string('name');
            // El prefijo es único dentro de cada comunidad (E01 puede existir en varias).
            $table->string('receipt_prefix', 8);
            $table->foreignUuid('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'receipt_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
