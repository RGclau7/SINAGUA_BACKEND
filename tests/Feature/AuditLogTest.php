<?php

namespace Tests\Feature;

use App\Audit\AuditChain;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Device;
use App\Models\Roles;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RF24 y CDU24: bitácora inmutable y su consulta.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create(['name' => 'Ana López'])->assignRole(Roles::ADMIN);
        Sanctum::actingAs($this->admin);
        $this->useCommunity($this->community);
    }

    public function test_each_entry_is_chained_to_the_previous_one(): void
    {
        $a = AuditLog::record('user.created', ['user_id' => $this->admin->id, 'data' => ['b' => 2, 'a' => 1]]);
        $b = AuditLog::record('user.updated', ['user_id' => $this->admin->id]);

        $this->assertNull($a->prev_hash);
        $this->assertSame($a->hash, $b->prev_hash);
        $this->assertSame(64, strlen($b->hash));
        $this->assertSame(['ok' => true, 'checked' => 2, 'broken_at' => null], AuditChain::verify($this->community->id));
    }

    public function test_database_refuses_to_modify_or_delete_entries(): void
    {
        $log = AuditLog::record('user.created', ['data' => ['email' => 'a@x.mx']]);

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'otra']);
    }

    public function test_database_refuses_to_delete_entries(): void
    {
        $log = AuditLog::record('user.created');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->delete();
    }

    public function test_a_forged_entry_breaks_the_chain(): void
    {
        AuditLog::record('user.created');
        app(CurrentCommunity::class)->runAsSystem(fn () => DB::table('audit_logs')->insert([
            'community_id' => $this->community->id,
            'action' => 'user.created',
            'created_at' => now(),
            'prev_hash' => str_repeat('0', 64),
            'hash' => str_repeat('f', 64),
        ]));

        $result = AuditChain::verify($this->community->id);
        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['checked']);

        $this->artisan('sinagua:verificar-bitacora')->assertFailed();
    }

    public function test_passwords_never_reach_the_log(): void
    {
        $log = AuditLog::record('sync.accepted', ['data' => [
            'payload' => ['email' => 'x@x.mx', 'password_hash' => '$2y$10$abc', 'nested' => ['password' => 'secreta']],
        ]]);

        $this->assertSame(['payload' => ['email' => 'x@x.mx', 'nested' => []]], $log->fresh()->data);
    }

    public function test_admin_queries_log_with_filters_and_pages_of_20(): void
    {
        $other = User::factory()->forCommunity($this->community)->create(['name' => 'José Ruiz']);
        for ($i = 0; $i < 22; $i++) {
            AuditLog::record('auth.login', ['user_id' => $other->id]);
        }
        AuditLog::record('user.created', ['user_id' => $this->admin->id, 'entity' => 'users']);

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 23)
            ->assertJsonPath('data.0.label', 'Registró un usuario')
            ->assertJsonPath('data.0.user_name', 'Ana López')
            ->assertJsonPath('data.0.category', 'usuarios');

        $this->getJson("/api/v1/audit-logs?user_id={$other->id}")->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/audit-logs?category=usuarios')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/audit-logs?from='.now()->addDay()->toDateString())
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.message', 'No hay registros para los filtros elegidos.');
        $this->getJson('/api/v1/audit-logs/verify')->assertJsonPath('data.ok', true);
    }

    public function test_log_is_only_for_admins_and_only_of_own_community(): void
    {
        $outside = Community::factory()->create();
        app(CurrentCommunity::class)->runAsSystem(fn () => AuditLog::record('auth.login', ['community_id' => $outside->id]));
        AuditLog::record('auth.login', ['user_id' => $this->admin->id]);

        $this->getJson('/api/v1/audit-logs')->assertJsonPath('meta.total', 1);

        Sanctum::actingAs(User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR));
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_log_reaches_admin_devices_read_only(): void
    {
        $deviceId = (string) Str::uuid();
        Device::create(['id' => $deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);
        AuditLog::record('user.created', ['user_id' => $this->admin->id, 'entity' => 'users']);

        $changes = collect($this->getJson("/api/v1/sync/pull?device_id={$deviceId}")->json('data.changes'))
            ->where('entity', 'bitacora');
        $this->assertGreaterThanOrEqual(1, $changes->count());
        $this->assertSame('Registró un usuario', $changes->last()['payload']['label']);

        $this->postJson('/api/v1/sync/push', ['device_id' => $deviceId, 'mutations' => [[
            'mutation_id' => (string) Str::uuid(), 'entity' => 'bitacora', 'entity_id' => (string) Str::uuid(),
            'op' => 'create', 'payload' => ['action' => 'falsa'],
        ]]])->assertJsonPath('data.results.0.code', 'read_only');
    }
}
