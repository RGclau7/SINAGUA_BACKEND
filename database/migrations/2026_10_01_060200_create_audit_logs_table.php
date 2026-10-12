<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora inmutable: solo se inserta, nunca se actualiza ni se borra.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('device_id')->nullable()->index();
            $table->string('action', 64);
            $table->string('entity', 64)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at');
            $table->index(['entity', 'entity_id']);
            $table->index(['community_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
