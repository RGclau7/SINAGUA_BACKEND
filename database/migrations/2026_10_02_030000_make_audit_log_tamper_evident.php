<?php

use App\Audit\AuditChain;
use App\Audit\AuditFeed;
use App\Models\AuditLog;
use App\Tenancy\CurrentCommunity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora inmutable (RF24): cadena de huellas SHA-256 por comunidad y la base de datos
     * rechaza cualquier UPDATE o DELETE sobre audit_logs.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('prev_hash', 64)->nullable();
            $table->string('hash', 64)->nullable()->index();
        });

        // Encadena los registros que ya existían.
        app(CurrentCommunity::class)->runAsSystem(function () {
            $previous = [];
            AuditLog::query()->orderBy('id')->each(function (AuditLog $log) use (&$previous) {
                $key = $log->community_id ?? 'system';
                $prev = $previous[$key] ?? null;
                $hash = AuditChain::hash($log, $prev);
                DB::table('audit_logs')->where('id', $log->id)->update(['prev_hash' => $prev, 'hash' => $hash]);
                $previous[$key] = $hash;

                // Para que el administrador vea también el historial anterior en su equipo.
                app(AuditFeed::class)->publish($log->forceFill(['prev_hash' => $prev, 'hash' => $hash]));
            });
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_logs_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'La bitácora es inmutable: no se puede modificar ni borrar.';
                END
                $$
            SQL);
            DB::statement('CREATE TRIGGER audit_logs_no_update BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_inmutable()');
            DB::statement('CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_inmutable()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'La bitácora es inmutable'); END");
            DB::statement("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, 'La bitácora es inmutable'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs');
            DB::statement('DROP FUNCTION IF EXISTS audit_logs_inmutable()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_update');
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_delete');
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['prev_hash', 'hash']);
        });
    }
};
