<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CategoriaIngreso;
use App\Models\Community;
use App\Models\Device;
use App\Models\Ingreso;
use App\Models\Roles;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ingresos extras (M8): categorías que crea la comunidad (cada una un fondo con su saldo)
 * y los ingresos de cada una, en línea y desde el escritorio sin conexión.
 */
class IngresosTest extends TestCase
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

    private function categoria(string $name = 'Baños comunitarios'): string
    {
        return $this->postJson('/api/v1/ingresos/categorias', ['name' => $name])->assertCreated()->json('data.id');
    }

    public function test_admin_creates_categories_and_each_keeps_its_own_balance(): void
    {
        $banos = $this->categoria();
        $salon = $this->categoria('Renta del salón');

        $this->postJson('/api/v1/ingresos/categorias', ['name' => 'BANOS comunitarios'])
            ->assertStatus(409)->assertJsonPath('error.code', 'duplicate_name');

        $this->postJson('/api/v1/ingresos', ['categoria_id' => $banos, 'amount_cents' => 25000, 'received_on' => now()->toDateString(), 'note' => 'Domingo'])
            ->assertCreated();
        $id = $this->postJson('/api/v1/ingresos', ['categoria_id' => $banos, 'amount_cents' => 10000, 'received_on' => now()->toDateString()])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/ingresos', ['categoria_id' => $salon, 'amount_cents' => 50000, 'received_on' => now()->toDateString()])
            ->assertCreated();

        $saldos = collect($this->getJson('/api/v1/ingresos/categorias')->assertOk()->json('data'))->pluck('saldo_cents', 'name');
        $this->assertSame(35000, $saldos['Baños comunitarios']);
        $this->assertSame(50000, $saldos['Renta del salón']);

        // Un ingreso mal registrado se anula con motivo y deja de contar.
        $this->postJson("/api/v1/movements/ingresos/{$id}/void", ['reason' => 'Se registró dos veces'])->assertOk();
        $this->assertSame(25000, CategoriaIngreso::findOrFail($banos)->saldoCents());
        $this->assertSame('void', SyncChange::where('entity', 'ingresos')->where('entity_id', $id)->latest('id')->value('op'));

        $this->assertCount(2, $this->getJson("/api/v1/ingresos?categoria_id={$banos}")->json('data'));
        $this->assertSame(1, AuditLog::where('action', 'categoria_ingreso.created')->where('entity_id', $salon)->count());
    }

    public function test_inactive_category_and_bad_amounts_are_rejected(): void
    {
        $banos = $this->categoria();

        $this->postJson('/api/v1/ingresos', ['categoria_id' => $banos, 'amount_cents' => 0, 'received_on' => now()->toDateString()])
            ->assertStatus(422);
        $this->postJson('/api/v1/ingresos', ['categoria_id' => $banos, 'amount_cents' => 100, 'received_on' => now()->addDays(5)->toDateString()])
            ->assertStatus(422);

        $this->patchJson("/api/v1/ingresos/categorias/{$banos}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/v1/ingresos', ['categoria_id' => $banos, 'amount_cents' => 100, 'received_on' => now()->toDateString()])
            ->assertStatus(422)->assertJsonPath('error.code', 'categoria_inactive');
    }

    public function test_desktop_creates_category_and_ingresos_offline_and_voids_one(): void
    {
        $categoria = (string) Str::uuid();
        $ingreso = (string) Str::uuid();

        $this->push([
            ['entity' => 'categorias_ingreso', 'entity_id' => $categoria, 'op' => 'create', 'payload' => ['name' => 'Donativos']],
            ['entity' => 'ingresos', 'entity_id' => $ingreso, 'op' => 'create',
                'payload' => ['categoria_id' => $categoria, 'amount_cents' => 12000, 'received_on' => now()->toDateString(), 'note' => 'Fiesta patronal']],
            ['entity' => 'ingresos', 'entity_id' => $ingreso, 'op' => 'update', 'payload' => ['amount_cents' => 1]],
            ['entity' => 'ingresos', 'entity_id' => $ingreso, 'op' => 'void', 'payload' => ['void_reason' => 'Era de otra categoría']],
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.1.status', 'accepted')
            ->assertJsonPath('data.results.2.code', 'append_only')
            ->assertJsonPath('data.results.3.status', 'accepted');

        $this->assertSame($this->deviceId, Ingreso::findOrFail($ingreso)->device_id);
        $this->assertNotNull(Ingreso::findOrFail($ingreso)->voided_at);

        $pull = collect($this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->json('data.changes'));
        $this->assertSame('Donativos', $pull->firstWhere('entity', 'categorias_ingreso')['payload']['name']);
        $this->assertSame('Era de otra categoría', $pull->where('entity', 'ingresos')->last()['payload']['void_reason']);
    }

    public function test_cobrador_neither_downloads_nor_registers_ingresos(): void
    {
        $this->categoria();
        $cobrador = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR);
        Sanctum::actingAs($cobrador);

        $this->getJson('/api/v1/ingresos/categorias')->assertForbidden();
        $pull = $this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->assertOk();
        $this->assertNotContains('categorias_ingreso', collect($pull->json('data.changes'))->pluck('entity')->all());
        $this->assertStringContainsString('categorias_ingreso', $pull->json('data.scope'));

        $this->push([['entity' => 'categorias_ingreso', 'entity_id' => (string) Str::uuid(), 'op' => 'create', 'payload' => ['name' => 'Otra']]])
            ->assertJsonPath('data.results.0.code', 'forbidden');
    }
}
