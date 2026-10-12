<?php

namespace Tests\Feature;

use App\Cuotas\CuotasService;
use App\Models\AuditLog;
use App\Models\Cargo;
use App\Models\Community;
use App\Models\Cuota;
use App\Models\Device;
use App\Models\Familia;
use App\Models\Roles;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cuotas con vigencia, generación de cargos por familia y anulación de cargos (M5).
 */
class CuotasTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo('2026-10-15 10:00:00');

        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        $this->useCommunity($this->community);
        Sanctum::actingAs($this->admin);
    }

    private function family(string $given, string $address): string
    {
        return $this->postJson('/api/v1/padron/registro', [
            'vivienda' => ['address' => $address],
            'titular' => ['given_names' => $given, 'paternal_surname' => 'López'],
            'familia' => ['name' => "Familia {$given}"],
        ])->assertCreated()->json('data.familia.id');
    }

    private function cuota(int $cents, string $from)
    {
        return $this->postJson('/api/v1/cuotas', ['amount_cents' => $cents, 'valid_from' => $from]);
    }

    public function test_current_cuota_is_the_latest_that_already_started(): void
    {
        $this->cuota(10000, '2026-01')->assertCreated()->assertJsonPath('data.valid_from', '2026-01-01');
        $this->cuota(15000, '2026-09')->assertCreated();
        $this->cuota(18000, '2027-01')->assertCreated();

        $list = $this->getJson('/api/v1/cuotas')->assertOk()->json('data');
        $this->assertSame([18000, 15000, 10000], array_column($list, 'amount_cents'));
        $this->assertSame([false, true, false], array_column($list, 'is_current'));

        $this->cuota(20000, '2026-09')->assertStatus(422)->assertJsonPath('error.code', 'cuota_exists');
        $this->cuota(0, '2026-12')->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_generates_one_cargo_per_active_family_and_can_be_repeated(): void
    {
        $this->cuota(15000, '2026-09')->assertCreated();
        $ana = $this->family('Ana', 'Calle 1');
        $beto = $this->family('Beto', 'Calle 2');
        $baja = $this->family('Carla', 'Calle 3');
        $this->postJson("/api/v1/padron/familias/{$baja}/deactivate")->assertOk();

        $first = $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 15000)
            ->assertJsonPath('notices.0.message', 'Se generaron 2 cargo(s) de octubre 2026 por $300.00.');

        $periodoId = $first->json('data.id');
        $this->assertSame(CuotasService::periodoId($this->community->id, 2026, 10), $periodoId);
        $this->assertEqualsCanonicalizing([$ana, $beto], Cargo::pluck('familia_id')->all());
        $this->assertSame(CuotasService::cargoId($periodoId, $ana), Cargo::where('familia_id', $ana)->value('id'));

        // Repetir no duplica; una familia nueva recibe su cargo.
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])
            ->assertOk()->assertJsonPath('notices.0.message', 'Todas las familias activas ya tenían su cargo de octubre 2026.');
        $this->family('Dora', 'Calle 4');
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])
            ->assertCreated()->assertJsonPath('notices.0.message', 'Se generaron 1 cargo(s) de octubre 2026 por $150.00.');
        $this->assertSame(3, Cargo::count());

        $this->getJson('/api/v1/periodos')->assertJsonPath('data.0.label', 'octubre 2026')
            ->assertJsonPath('data.0.cargos', 3)
            ->assertJsonPath('data.0.total_cents', 45000);
        $this->getJson("/api/v1/periodos/{$periodoId}/cargos?search=beto")->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.vivienda_label', 'Calle 2');
        $this->assertSame(2, AuditLog::where('action', 'periodo.generated')->count());
        $this->assertSame(3, SyncChange::where('entity', 'cargos')->count());
    }

    public function test_month_without_cuota_or_too_far_ahead_is_refused(): void
    {
        $this->cuota(15000, '2026-09')->assertCreated();

        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 8])
            ->assertStatus(422)->assertJsonPath('error.code', 'no_cuota')
            ->assertJsonPath('error.message', 'No hay una cuota vigente para agosto 2026. Registre primero la cuota.');
        $this->postJson('/api/v1/periodos/generar', ['year' => 2028, 'month' => 1])->assertStatus(422);
    }

    public function test_used_cuota_amount_cannot_change_but_new_cuota_keeps_old_cargos(): void
    {
        $cuotaId = $this->cuota(15000, '2026-09')->json('data.id');
        $this->family('Ana', 'Calle 1');
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])->assertCreated();

        $this->patchJson("/api/v1/cuotas/{$cuotaId}", ['amount_cents' => 20000])
            ->assertStatus(422)->assertJsonPath('error.code', 'cuota_in_use');
        $this->patchJson("/api/v1/cuotas/{$cuotaId}", ['note' => 'Acuerdo de asamblea'])->assertOk();

        $this->cuota(20000, '2026-11')->assertCreated();
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 11])->assertCreated();
        $this->assertSame([15000, 20000], Cargo::orderBy('month')->pluck('amount_cents')->all());
    }

    public function test_cargo_is_voided_with_reason_and_stops_counting_as_debt(): void
    {
        $this->cuota(15000, '2026-09')->assertCreated();
        $familia = $this->family('Ana', 'Calle 1');
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])->assertCreated();
        $cargo = Cargo::firstOrFail();

        // Con cargos pendientes la baja pide confirmación.
        $this->postJson("/api/v1/padron/familias/{$familia}/deactivate")
            ->assertStatus(409)->assertJsonPath('error.details.amount_cents', 15000);

        $this->postJson("/api/v1/movements/cargos/{$cargo->id}/void", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/movements/cargos/{$cargo->id}/void", ['reason' => 'Se generó por error'])->assertOk();
        $this->assertNotNull($cargo->fresh()->voided_at);
        $this->assertSame('void', SyncChange::where('entity', 'cargos')->latest('id')->value('op'));

        $this->postJson("/api/v1/padron/familias/{$familia}/deactivate")->assertOk();
        $this->getJson('/api/v1/periodos')->assertJsonPath('data.0.anulados', 1)->assertJsonPath('data.0.total_cents', 0);
    }

    public function test_desktop_generates_month_offline_with_the_same_ids(): void
    {
        $deviceId = (string) Str::uuid();
        Device::create(['id' => $deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);
        $cuotaId = (string) Str::uuid();
        $ana = $this->family('Ana', 'Calle 1');
        $periodoId = CuotasService::periodoId($this->community->id, 2026, 10);

        $push = fn (array $mutations) => $this->postJson('/api/v1/sync/push', [
            'device_id' => $deviceId,
            'mutations' => array_map(fn ($m) => ['mutation_id' => (string) Str::uuid(), ...$m], $mutations),
        ]);

        $push([
            ['entity' => 'cuotas', 'entity_id' => $cuotaId, 'op' => 'create', 'payload' => ['amount_cents' => 12000, 'valid_from' => '2026-10-01', 'note' => null]],
            ['entity' => 'periodos', 'entity_id' => $periodoId, 'op' => 'create', 'payload' => ['year' => 2026, 'month' => 10]],
        ])->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.1.status', 'accepted')
            ->assertJsonPath('data.results.1.notices.0.message', 'Se generaron 1 cargo(s) de octubre 2026 por $120.00.');

        // Otro equipo generó el mismo mes sin conexión: no se duplica.
        $push([['entity' => 'periodos', 'entity_id' => $periodoId, 'op' => 'create', 'payload' => ['year' => 2026, 'month' => 10]]])
            ->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertSame(1, Cargo::count());
        $cargoId = CuotasService::cargoId($periodoId, $ana);
        $this->assertTrue(Cargo::whereKey($cargoId)->exists());

        $pull = collect($this->getJson("/api/v1/sync/pull?device_id={$deviceId}")->json('data.changes'));
        $this->assertSame([$cargoId], $pull->where('entity', 'periodos')->last()['payload']['cargo_ids']);

        // Id que no corresponde al mes, cargos que se intentan crear o editar: rechazados.
        $push([['entity' => 'periodos', 'entity_id' => (string) Str::uuid(), 'op' => 'create', 'payload' => ['year' => 2026, 'month' => 9]]])
            ->assertJsonPath('data.results.0.code', 'invalid_id');
        $push([['entity' => 'cargos', 'entity_id' => (string) Str::uuid(), 'op' => 'create', 'payload' => []]])
            ->assertJsonPath('data.results.0.code', 'not_supported');
        $push([['entity' => 'cargos', 'entity_id' => $cargoId, 'op' => 'void', 'payload' => ['void_reason' => 'Familia exenta por acuerdo']]])
            ->assertJsonPath('data.results.0.status', 'accepted');
        $this->assertSame('Familia exenta por acuerdo', Cargo::find($cargoId)->void_reason);

        // El cobrador descarga los cargos (para cobrar), pero no las cuotas ni los meses.
        Sanctum::actingAs(User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR));
        $entities = collect($this->getJson("/api/v1/sync/pull?device_id={$deviceId}")->json('data.changes'))->pluck('entity')->unique();
        $this->assertContains('cargos', $entities);
        $this->assertNotContains('cuotas', $entities);
        $this->assertNotContains('periodos', $entities);
    }

    public function test_other_community_cannot_see_cuotas_or_cargos(): void
    {
        $this->cuota(15000, '2026-09')->assertCreated();
        $this->family('Ana', 'Calle 1');
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])->assertCreated();

        $other = Community::factory()->create();
        Sanctum::actingAs(User::factory()->forCommunity($other)->create()->assignRole(Roles::ADMIN));
        $this->useCommunity(null);
        $this->getJson('/api/v1/cuotas')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/periodos')->assertJsonPath('meta.total', 0);
        // Su propio octubre no choca con el de la otra comunidad.
        $this->postJson('/api/v1/cuotas', ['amount_cents' => 9000, 'valid_from' => '2026-09'])->assertCreated();
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])->assertOk();
        $this->assertSame(0, Familia::count());
    }

    public function test_assigning_a_month_with_an_amount_sets_the_cuota_from_that_month(): void
    {
        $this->family('Ana', 'Calle 1');
        // Sin cuota registrada: el monto del mes la crea.
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10, 'amount_cents' => 15000])->assertCreated();
        $this->assertSame('2026-10-01', Cuota::sole()->valid_from->format('Y-m-d'));

        // Noviembre con otro monto: cuota nueva desde noviembre; diciembre hereda el de noviembre.
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 11, 'amount_cents' => 18000])->assertCreated();
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 12])->assertCreated();
        $this->assertSame([15000, 18000, 18000], Cargo::orderBy('month')->pluck('amount_cents')->all());

        // Un mes ya asignado no cambia de monto.
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10, 'amount_cents' => 20000])
            ->assertStatus(422)->assertJsonPath('error.code', 'month_has_cuota');
    }

    public function test_multas_are_charges_with_a_reason_for_chosen_families(): void
    {
        $ana = $this->family('Ana', 'Calle 1');
        $beto = $this->family('Beto', 'Calle 2');

        $this->postJson('/api/v1/multas', ['familia_ids' => [$ana, $beto], 'description' => 'No asistió a la faena', 'amount_cents' => 10000, 'year' => 2026, 'month' => 10])
            ->assertCreated()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.concept', 'multa')
            ->assertJsonPath('data.0.periodo_id', null);
        $this->postJson('/api/v1/multas', ['familia_ids' => [$ana], 'description' => '', 'amount_cents' => 10000, 'year' => 2026, 'month' => 10])
            ->assertStatus(422);

        $this->getJson('/api/v1/multas')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.description', 'No asistió a la faena');
        $this->assertSame(2, AuditLog::where('action', 'multa.created')->count());

        // Cuenta como adeudo y se anula como cualquier cargo.
        $this->postJson("/api/v1/padron/familias/{$ana}/deactivate")->assertStatus(409)->assertJsonPath('error.details.amount_cents', 10000);
        $multa = Cargo::where('familia_id', $ana)->first();
        $this->postJson("/api/v1/movements/cargos/{$multa->id}/void", ['reason' => 'Sí asistió, error de lista'])->assertOk();

        // Generar el mes no confunde la multa con la cuota.
        $this->cuota(15000, '2026-10')->assertCreated();
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])->assertJsonPath('notices.0.message', 'Se generaron 2 cargo(s) de octubre 2026 por $300.00.');
    }

    public function test_desktop_creates_multa_offline(): void
    {
        $deviceId = (string) Str::uuid();
        Device::create(['id' => $deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);
        $ana = $this->family('Ana', 'Calle 1');
        $id = (string) Str::uuid();

        $this->postJson('/api/v1/sync/push', ['device_id' => $deviceId, 'mutations' => [[
            'mutation_id' => (string) Str::uuid(), 'entity' => 'cargos', 'entity_id' => $id, 'op' => 'create',
            'payload' => ['concept' => 'multa', 'familia_id' => $ana, 'description' => 'Tirar agua', 'amount_cents' => 5000, 'year' => 2026, 'month' => 10, 'periodo_id' => null],
        ]]])->assertJsonPath('data.results.0.status', 'accepted');

        $this->assertSame('Tirar agua', Cargo::findOrFail($id)->description);
    }
}
