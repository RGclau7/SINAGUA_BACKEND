<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Padrón (M4, RF3 a RF5): viviendas (domicilios), familias y habitantes.
     *
     * - Un domicilio puede tener varias familias: la cuota se cobra por familia (M5).
     * - Nada se borra: la baja es lógica (is_active = false) y conserva el historial.
     * - Los UUID los genera el equipo, para dar de alta sin conexión.
     */
    public const TABLES = ['viviendas', 'familias', 'habitantes'];

    public function up(): void
    {
        Schema::create('viviendas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->string('address');              // calle y número
            $table->string('neighborhood')->nullable(); // barrio o colonia
            $table->string('reference')->nullable();    // "frente a la capilla"
            // Domicilio normalizado para avisar si ya hay familias en él (CDU3, 4a).
            $table->string('address_key');
            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason')->nullable();
            $table->timestamps();
            $table->index(['community_id', 'address_key']);
            $table->index(['community_id', 'address']);
        });

        Schema::create('familias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('vivienda_id')->constrained('viviendas');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason')->nullable();
            $table->timestamps();
            $table->index(['community_id', 'name']);
        });

        Schema::create('habitantes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('familia_id')->constrained('familias');
            $table->string('name');
            $table->string('given_names', 120);
            $table->string('paternal_surname', 80);
            $table->string('maternal_surname', 80)->nullable();
            $table->string('phone', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason')->nullable();
            $table->timestamps();
            $table->index(['community_id', 'name']);
        });

        // Titular de la familia: quien responde por la cuota. Se agrega después porque
        // familias y habitantes se apuntan entre sí.
        Schema::table('familias', function (Blueprint $table) {
            $table->foreignUuid('head_id')->nullable()->after('name')->constrained('habitantes')->nullOnDelete();
        });

        // Segunda barrera de aislamiento, igual que en las demás tablas de comunidad.
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

        Schema::table('familias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_id');
        });
        Schema::dropIfExists('habitantes');
        Schema::dropIfExists('familias');
        Schema::dropIfExists('viviendas');
    }
};
