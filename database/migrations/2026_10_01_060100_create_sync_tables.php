<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro de cada mutación recibida por /sync/push. La llave es el UUID
        // de la mutación, así reenviar la misma cola es idempotente.
        Schema::create('sync_mutations', function (Blueprint $table) {
            $table->uuid('mutation_id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('device_id')->constrained('devices');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity', 64);
            $table->uuid('entity_id');
            $table->string('op', 16);
            $table->string('status', 16); // accepted | rejected
            $table->string('reason_code', 64)->nullable();
            $table->text('reason_message')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->index(['entity', 'entity_id']);
        });

        // Feed de cambios del servidor. El id autoincremental es el cursor de /sync/pull.
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->string('entity', 64);
            $table->uuid('entity_id');
            $table->string('op', 16);
            $table->json('payload')->nullable();
            $table->uuid('origin_device_id')->nullable();
            $table->uuid('mutation_id')->nullable();
            $table->timestamp('created_at');
            $table->index(['entity', 'entity_id']);
            $table->index(['community_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('sync_mutations');
    }
};
