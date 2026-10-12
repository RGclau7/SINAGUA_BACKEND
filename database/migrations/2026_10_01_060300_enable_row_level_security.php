<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Segunda barrera de aislamiento entre comunidades (la primera es el filtro global de
     * Eloquent). La API fija app.community_id en cada petición; PostgreSQL solo deja leer y
     * escribir filas de esa comunidad, aunque una consulta olvide el filtro.
     *
     * Requiere que la API se conecte con un rol que NO sea superusuario ni tenga BYPASSRLS.
     */
    public const TABLES = ['devices', 'sync_mutations', 'sync_changes', 'audit_logs'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_can_access(row_community uuid) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT current_setting('app.system', true) = 'on'
                    OR row_community::text = nullif(current_setting('app.community_id', true), '')
            $$
        SQL);

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_community ON {$table} USING (app_can_access(community_id)) WITH CHECK (app_can_access(community_id))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_community ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
        DB::statement('DROP FUNCTION IF EXISTS app_can_access(uuid)');
    }
};
