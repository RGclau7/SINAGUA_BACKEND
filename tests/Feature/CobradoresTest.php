<?php

namespace Tests\Feature;

use App\Models\Asignacion;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Device;
use App\Models\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Asignación de viviendas a cobradores (M6) y lo que cada cobrador puede descargar.
 */
class CobradoresTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    private User $jose;

    private User $rosa;

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        $this->jose = User::factory()->forCommunity($this->community)->create(['name' => 'José Cobrador'])->assignRole(Roles::COBRADOR);
        $this->rosa = User::factory()->forCommunity($this->community)->create(['name' => 'Rosa Cobradora'])->assignRole(Roles::COBRADOR);
        $this->useCommunity($this->community);
        $this->deviceId = (string) Str::uuid();
        Device::create(['id' => $this->deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);
        Sanctum::actingAs($this->admin);
    }

    /** @return array{vivienda: string, familia: string, titular: string} */
    private function family(string $given, string $address): array
    {
        $data = $this->postJson('/api/v1/padron/registro', [
            'vivienda' => ['address' => $address],
            'titular' => ['given_names' => $given, 'paternal_surname' => 'López', 'phone' => '4151112233'],
            'familia' => ['name' => "Familia {$given}"],
        ])->assertCreated()->json('data');

        return ['vivienda' => $data['vivienda']['id'], 'familia' => $data['familia']['id'], 'titular' => $data['titular']['id']];
    }

    private function assign(User $cobrador, array $viviendas)
    {
        return $this->postJson('/api/v1/asignaciones', ['cobrador_id' => $cobrador->id, 'vivienda_ids' => $viviendas]);
    }

    private function pullAs(User $user): array
    {
        Sanctum::actingAs($user);
        $data = $this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->assertOk()->json('data');
        Sanctum::actingAs($this->admin);

        return $data;
    }

    public function test_a_vivienda_has_one_cobrador_and_reassigning_keeps_history(): void
    {
        $a = $this->family('Ana', 'Calle 1');
        $b = $this->family('Beto', 'Calle 2');

        $this->assign($this->jose, [$a['vivienda'], $b['vivienda']])->assertCreated()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/cobradores')->assertJsonPath('data.0.name', 'José Cobrador')->assertJsonPath('data.0.viviendas', 2);
        $this->assign($this->jose, [$a['vivienda']])->assertStatus(409)->assertJsonPath('error.code', 'already_assigned');

        // Pasar Calle 1 a Rosa cierra la asignación de José y la conserva.
        $this->assign($this->rosa, [$a['vivienda']])->assertCreated();
        $this->assertSame(1, Asignacion::vigentes()->where('vivienda_id', $a['vivienda'])->count());
        $this->assertSame(2, Asignacion::where('vivienda_id', $a['vivienda'])->count());
        $this->getJson("/api/v1/asignaciones?vivienda_id={$a['vivienda']}&historial=1")->assertJsonCount(2, 'data');
        $this->assertSame('José Cobrador', AuditLog::where('action', 'asignacion.created')->latest('id')->first()->data['previous_cobrador']);

        // Pasar todas las de José a Rosa.
        $this->postJson('/api/v1/asignaciones/reasignar', ['from_cobrador_id' => $this->jose->id, 'to_cobrador_id' => $this->rosa->id])
            ->assertOk()->assertJsonPath('notices.0.message', '1 vivienda(s) pasaron a otro cobrador.');
        $this->assertSame(0, Asignacion::vigentes()->where('cobrador_id', $this->jose->id)->count());
        $this->assertSame(2, Asignacion::vigentes()->where('cobrador_id', $this->rosa->id)->count());

        $id = Asignacion::vigentes()->where('vivienda_id', $b['vivienda'])->value('id');
        $this->postJson("/api/v1/asignaciones/{$id}/end")->assertOk()->assertJsonPath('data.ends_on', now()->toDateString());
        $this->postJson("/api/v1/asignaciones/{$id}/end")->assertStatus(409);
    }

    public function test_only_active_cobradores_of_the_community_receive_viviendas(): void
    {
        $a = $this->family('Ana', 'Calle 1');
        $this->assign($this->admin, [$a['vivienda']])->assertStatus(422)->assertJsonPath('error.code', 'invalid_cobrador');
        $this->rosa->forceFill(['is_active' => false])->save();
        $this->assign($this->rosa, [$a['vivienda']])->assertStatus(422);

        $other = User::factory()->forCommunity(Community::factory()->create())->create()->assignRole(Roles::COBRADOR);
        $this->assign($other, [$a['vivienda']])->assertStatus(422);
    }

    public function test_cobrador_downloads_every_titular_to_collect_but_not_users_or_cuotas(): void
    {
        $this->postJson('/api/v1/cuotas', ['amount_cents' => 15000, 'valid_from' => now()->format('Y-m')])->assertCreated();
        $ana = $this->family('Ana', 'Calle 1');
        $beto = $this->family('Beto', 'Calle 2');
        $this->postJson('/api/v1/periodos/generar', ['year' => (int) now()->format('Y'), 'month' => (int) now()->format('n')])->assertCreated();

        // La gente va con el cobrador a pagar: descarga a todos los titulares y sus cargos.
        $pull = $this->pullAs($this->jose);
        $ids = collect($pull['changes'])->groupBy('entity')->map(fn ($c) => $c->pluck('entity_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing([$ana['familia'], $beto['familia']], $ids['familias']);
        $this->assertCount(2, $ids['cargos']);
        $this->assertArrayNotHasKey('cuotas', $ids->all());
        $this->assertArrayNotHasKey('usuarios', $ids->all());
        $this->assertArrayNotHasKey('bitacora', $ids->all());

        // Consulta, pero no modifica el padrón ni las asignaciones.
        Sanctum::actingAs($this->jose);
        $this->postJson('/api/v1/sync/push', ['device_id' => $this->deviceId, 'mutations' => [[
            'mutation_id' => (string) Str::uuid(), 'entity' => 'viviendas', 'entity_id' => $ana['vivienda'], 'op' => 'update',
            'payload' => ['address' => 'Cambiada'],
        ]]])->assertJsonPath('data.results.0.code', 'forbidden');
        $this->getJson('/api/v1/padron/familias')->assertForbidden();
        $this->getJson('/api/v1/asignaciones')->assertForbidden();
    }

    public function test_desktop_assigns_and_ends_offline(): void
    {
        $a = $this->family('Ana', 'Calle 1');
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $push = fn (array $m) => $this->postJson('/api/v1/sync/push', ['device_id' => $this->deviceId, 'mutations' => [['mutation_id' => (string) Str::uuid(), 'entity' => 'asignaciones', ...$m]]]);

        $push(['entity_id' => $first, 'op' => 'create', 'payload' => ['cobrador_id' => $this->jose->id, 'vivienda_id' => $a['vivienda']]])
            ->assertJsonPath('data.results.0.status', 'accepted');
        $push(['entity_id' => $second, 'op' => 'create', 'payload' => ['cobrador_id' => $this->rosa->id, 'vivienda_id' => $a['vivienda']]])
            ->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertNotNull(Asignacion::find($first)->ends_on);

        $push(['entity_id' => $second, 'op' => 'update', 'payload' => ['ends_on' => now()->toDateString()]])->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertSame(0, Asignacion::vigentes()->count());

        // El aviso al desactivar a un cobrador con viviendas ya es real.
        $this->assign($this->jose, [$a['vivienda']])->assertCreated();
        $this->postJson("/api/v1/users/{$this->jose->id}/deactivate")->assertOk()
            ->assertJsonPath('notices.0.code', 'reassign_viviendas');

        Sanctum::actingAs($this->rosa);
        $push(['entity_id' => (string) Str::uuid(), 'op' => 'create', 'payload' => ['cobrador_id' => $this->rosa->id, 'vivienda_id' => $a['vivienda']]])
            ->assertJsonPath('data.results.0.code', 'forbidden');
    }
}
