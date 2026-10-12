<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Tenancy\CurrentCommunity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Segunda barrera: aunque una consulta se salte Eloquent (DB::table, SQL crudo),
 * PostgreSQL no deja ver ni escribir filas de otra comunidad. Solo corre en PostgreSQL.
 */
class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Community $a;

    private Community $b;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->usesPostgres()) {
            $this->markTestSkipped('Row-Level Security solo existe en PostgreSQL.');
        }

        $this->a = Community::factory()->create();
        $this->b = Community::factory()->create();

        app(CurrentCommunity::class)->runAsSystem(function () {
            foreach ([$this->a, $this->b] as $i => $community) {
                DB::table('devices')->insert([
                    'id' => (string) Str::uuid(),
                    'community_id' => $community->id,
                    'name' => "Caja {$i}",
                    'receipt_prefix' => 'E01',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function test_app_role_is_subject_to_rls(): void
    {
        $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $this->assertFalse($role->rolsuper, 'La API no debe conectarse como superusuario: ignoraría RLS.');
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_raw_queries_only_see_current_community(): void
    {
        $this->useCommunity($this->a);
        $rows = DB::table('devices')->pluck('community_id')->all();
        $this->assertSame([$this->a->id], $rows);

        $this->useCommunity(null);
        $this->assertSame(0, DB::table('devices')->count());
    }

    public function test_raw_insert_into_other_community_is_refused(): void
    {
        $this->useCommunity($this->a);

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->insert([
            'community_id' => $this->b->id,
            'action' => 'intruso',
            'created_at' => now(),
        ]);
    }

    public function test_raw_update_cannot_touch_other_community(): void
    {
        $this->useCommunity($this->a);

        $affected = DB::table('devices')->where('community_id', $this->b->id)->update(['name' => 'hackeado']);

        $this->assertSame(0, $affected);
        $this->assertSame(0, app(CurrentCommunity::class)->runAsSystem(
            fn () => DB::table('devices')->where('name', 'hackeado')->count()
        ));
    }

    public function test_padron_tables_are_isolated_too(): void
    {
        app(CurrentCommunity::class)->runAsSystem(function () {
            DB::table('viviendas')->insert([
                'id' => (string) Str::uuid(),
                'community_id' => $this->b->id,
                'address' => 'Calle de la otra comunidad 1',
                'address_key' => 'calle de la otra comunidad 1',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->useCommunity($this->a);
        $this->assertSame(0, DB::table('viviendas')->count());

        $this->expectException(QueryException::class);
        DB::table('viviendas')->insert([
            'id' => (string) Str::uuid(),
            'community_id' => $this->b->id,
            'address' => 'Intruso',
            'address_key' => 'intruso',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_ingresos_extras_are_isolated_too(): void
    {
        app(CurrentCommunity::class)->runAsSystem(function () {
            DB::table('categorias_ingreso')->insert([
                'id' => (string) Str::uuid(),
                'community_id' => $this->b->id,
                'name' => 'Baños de la otra comunidad',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->useCommunity($this->a);
        $this->assertSame(0, DB::table('categorias_ingreso')->count());

        $this->expectException(QueryException::class);
        DB::table('categorias_ingreso')->insert([
            'id' => (string) Str::uuid(),
            'community_id' => $this->b->id,
            'name' => 'Intruso',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
