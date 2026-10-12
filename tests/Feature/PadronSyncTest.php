<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Device;
use App\Models\Familia;
use App\Models\Habitante;
use App\Models\Roles;
use App\Models\User;
use App\Models\Vivienda;
use App\Padron\Debts;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Padrón hecho en el escritorio, quizá sin conexión, vía /sync.
 */
class PadronSyncTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        $this->useCommunity($this->community);
        $this->deviceId = (string) Str::uuid();
        Device::create(['id' => $this->deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);
        Sanctum::actingAs($this->admin);
    }

    /** @param list<array> $mutations */
    private function push(array $mutations)
    {
        return $this->postJson('/api/v1/sync/push', [
            'device_id' => $this->deviceId,
            'mutations' => array_map(fn ($m) => ['mutation_id' => (string) Str::uuid(), ...$m], $mutations),
        ]);
    }

    /**
     * Lo que el escritorio encola al registrar una familia sin conexión, en orden.
     *
     * @return array{vivienda: string, familia: string, titular: string}
     */
    private function registerOffline(): array
    {
        $ids = ['vivienda' => (string) Str::uuid(), 'familia' => (string) Str::uuid(), 'titular' => (string) Str::uuid()];

        $this->push([
            ['entity' => 'viviendas', 'entity_id' => $ids['vivienda'], 'op' => 'create',
                'payload' => ['address' => 'Calle Hidalgo 10', 'neighborhood' => 'Barrio Alto', 'reference' => null, 'is_active' => true]],
            ['entity' => 'familias', 'entity_id' => $ids['familia'], 'op' => 'create',
                'payload' => ['vivienda_id' => $ids['vivienda'], 'name' => 'López Ruiz', 'head_id' => null, 'is_active' => true]],
            ['entity' => 'habitantes', 'entity_id' => $ids['titular'], 'op' => 'create',
                'payload' => ['familia_id' => $ids['familia'], 'given_names' => 'José', 'paternal_surname' => 'López',
                    'maternal_surname' => 'Ruiz', 'phone' => '', 'is_active' => true]],
            ['entity' => 'familias', 'entity_id' => $ids['familia'], 'op' => 'update',
                'payload' => ['vivienda_id' => $ids['vivienda'], 'name' => 'López Ruiz', 'head_id' => $ids['titular'], 'is_active' => true]],
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.1.status', 'accepted')
            ->assertJsonPath('data.results.2.status', 'accepted')
            ->assertJsonPath('data.results.3.status', 'accepted');

        return $ids;
    }

    public function test_family_registered_offline_keeps_device_ids(): void
    {
        $ids = $this->registerOffline();

        $familia = Familia::findOrFail($ids['familia']);
        $this->assertSame($ids['vivienda'], $familia->vivienda_id);
        $this->assertSame($ids['titular'], $familia->head_id);
        $this->assertSame($this->community->id, $familia->community_id);
        $this->assertNull(Habitante::findOrFail($ids['titular'])->phone);
        $this->assertSame('José López Ruiz', Habitante::findOrFail($ids['titular'])->name);
        $this->assertSame(1, AuditLog::where('action', 'familia.created')->where('entity_id', $ids['familia'])->count());
    }

    public function test_padron_feed_reaches_admin_and_cobradores_but_only_admin_writes(): void
    {
        $ids = $this->registerOffline();

        $admin = $this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->assertOk();
        $entities = collect($admin->json('data.changes'))->pluck('entity')->unique()->values()->all();
        $this->assertContains('viviendas', $entities);
        $this->assertContains('familias', $entities);
        $this->assertContains('habitantes', $entities);
        $last = collect($admin->json('data.changes'))->where('entity', 'familias')->last();
        $this->assertSame($ids['titular'], $last['payload']['head_id']);

        $cobrador = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR);
        Sanctum::actingAs($cobrador);
        $pull = $this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->assertOk();
        $this->assertContains('familias', collect($pull->json('data.changes'))->pluck('entity')->all());
        // El cobrador no ve usuarios, bitácora ni cuotas; sí todo el padrón, cargos y pagos (cobra a todos).
        $this->assertStringStartsWith('hidden:bitacora,categorias_ingreso,cuotas,ingresos,periodos,usuarios|restricted:asignaciones:', $pull->json('data.scope'));

        $this->push([['entity' => 'viviendas', 'entity_id' => (string) Str::uuid(), 'op' => 'create', 'payload' => ['address' => 'Calle 1']]])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'forbidden');
    }

    public function test_offline_deactivation_cascades_and_feeds_children(): void
    {
        $ids = $this->registerOffline();

        $this->push([['entity' => 'viviendas', 'entity_id' => $ids['vivienda'], 'op' => 'update',
            'payload' => ['address' => 'Calle Hidalgo 10', 'neighborhood' => 'Barrio Alto', 'is_active' => false, 'deactivation_reason' => 'Casa abandonada']]])
            ->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.0.notices.0.code', 'familias_deactivated');

        $this->assertFalse(Vivienda::findOrFail($ids['vivienda'])->is_active);
        $this->assertFalse(Familia::findOrFail($ids['familia'])->is_active);
        $this->assertFalse(Habitante::findOrFail($ids['titular'])->is_active);

        // Los registros que cambiaron en cascada también bajan a los equipos.
        $changes = collect($this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->json('data.changes'));
        $this->assertFalse($changes->where('entity', 'habitantes')->last()['payload']['is_active']);
        $this->assertSame('Baja de la vivienda', $changes->where('entity', 'familias')->last()['payload']['deactivation_reason']);
    }

    public function test_offline_deactivation_with_debt_needs_the_confirmation_flag(): void
    {
        $this->app->instance(Debts::class, new class implements Debts
        {
            public function owedByFamilia(Familia $familia): int
            {
                return 12000;
            }

            public function owedByVivienda(Vivienda $vivienda): int
            {
                return 12000;
            }
        });
        $ids = $this->registerOffline();
        $payload = ['vivienda_id' => $ids['vivienda'], 'name' => 'López Ruiz', 'is_active' => false];

        $this->push([['entity' => 'familias', 'entity_id' => $ids['familia'], 'op' => 'update', 'payload' => $payload]])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'has_debt');

        $this->push([['entity' => 'familias', 'entity_id' => $ids['familia'], 'op' => 'update', 'payload' => [...$payload, 'confirm_debt' => true]]])
            ->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.0.notices.0.code', 'had_debt');
    }

    public function test_family_for_vivienda_deactivated_elsewhere_is_rejected(): void
    {
        $ids = $this->registerOffline();
        $this->postJson("/api/v1/padron/viviendas/{$ids['vivienda']}/deactivate")->assertOk();

        $this->push([['entity' => 'familias', 'entity_id' => (string) Str::uuid(), 'op' => 'create',
            'payload' => ['vivienda_id' => $ids['vivienda'], 'name' => 'Martínez']]])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'parent_inactive');
    }

    public function test_stale_edit_from_other_device_loses(): void
    {
        $ids = $this->registerOffline();
        $base = Habitante::findOrFail($ids['titular'])->updated_at->toIso8601String();
        $this->travel(5)->minutes();

        $this->patchJson("/api/v1/padron/habitantes/{$ids['titular']}", ['phone' => '4159998877'])->assertOk();

        $this->push([['entity' => 'habitantes', 'entity_id' => $ids['titular'], 'op' => 'update',
            'payload' => ['given_names' => 'Pepe', 'base_updated_at' => $base]]])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'conflict');

        $this->assertSame('José', Habitante::findOrFail($ids['titular'])->given_names);
    }

    public function test_padron_is_never_deleted(): void
    {
        $ids = $this->registerOffline();

        $this->push([['entity' => 'habitantes', 'entity_id' => $ids['titular'], 'op' => 'delete', 'payload' => []]])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'not_supported');
        $this->assertTrue(Habitante::whereKey($ids['titular'])->exists());
    }
}
