<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        // Eventos del superadministrador (sin comunidad) también van a la bitácora.
        // Con RLS, una fila sin comunidad solo es visible en modo sistema.
        // En PostgreSQL la columna está en la política de RLS: solo se quita el NOT NULL.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN community_id DROP NOT NULL');
        } else {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->uuid('community_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_until', 'last_login_at']);
        });
    }
};
