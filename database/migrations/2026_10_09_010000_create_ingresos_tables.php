<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ingresos extras (M8): además de las cuotas del agua, la comunidad recibe dinero por otras
     * cosas (baños comunitarios, renta del salón, donativos…). El administrador crea las
     * categorías que necesite y cada una es un fondo con su propio saldo. El ingreso es un
     * movimiento: no se edita ni se borra; si está mal, se anula con motivo.
     */
    public const TABLES = ['categorias_ingreso', 'ingresos'];

    public function up(): void
    {
        Schema::create('categorias_ingreso', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['community_id', 'name']);
        });

        Schema::create('ingresos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('community_id')->constrained('communities');
            $table->foreignUuid('categoria_id')->constrained('categorias_ingreso');
            $table->unsignedInteger('amount_cents');
            $table->date('received_on');
            $table->string('note', 255)->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->uuid('device_id')->nullable();
            $table->voidable();
            $table->timestamps();
            $table->index(['community_id', 'categoria_id', 'received_on']);
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
        Schema::dropIfExists('ingresos');
        Schema::dropIfExists('categorias_ingreso');
    }
};
