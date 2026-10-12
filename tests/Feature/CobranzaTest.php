<?php

namespace Tests\Feature;

use App\Cobranza\CobranzaService;
use App\Cobranza\CobroAutomatico;
use App\Models\AuditLog;
use App\Models\Cargo;
use App\Models\Community;
use App\Models\Device;
use App\Models\Pago;
use App\Models\Roles;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cobranza (M7): cada cargo se paga completo, con folio; el cobrador solo cobra lo suyo.
 */
class CobranzaTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    private User $cobrador;

    private string $deviceId;

    /** @var array{vivienda: string, familia: string} */
    private array $ana;

    /** @var array{vivienda: string, familia: string} */
    private array $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->travelTo('2026-10-15 10:00:00');

        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create(['name' => 'Ana Admin'])->assignRole(Roles::ADMIN);
        $this->cobrador = User::factory()->forCommunity($this->community)->create(['name' => 'José Cobrador'])->assignRole(Roles::COBRADOR);
        $this->useCommunity($this->community);
        $this->deviceId = (string) Str::uuid();
        Device::create(['id' => $this->deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);
        Sanctum::actingAs($this->admin);

        $this->ana = $this->family('Ana', 'Calle 1');
        $this->beto = $this->family('Beto', 'Calle 2');
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 9, 'amount_cents' => 15000])->assertCreated();
        $this->postJson('/api/v1/periodos/generar', ['year' => 2026, 'month' => 10])->assertCreated();
        $this->postJson('/api/v1/multas', ['familia_ids' => [$this->ana['familia']], 'description' => 'No asistió a la faena', 'amount_cents' => 10000, 'year' => 2026, 'month' => 10])->assertCreated();
        $this->postJson('/api/v1/asignaciones', ['cobrador_id' => $this->cobrador->id, 'vivienda_ids' => [$this->ana['vivienda']]])->assertCreated();
    }

    /** @return array{vivienda: string, familia: string} */
    private function family(string $given, string $address): array
    {
        $data = $this->postJson('/api/v1/padron/registro', [
            'vivienda' => ['address' => $address],
            'titular' => ['given_names' => $given, 'paternal_surname' => 'López'],
            'familia' => ['name' => "Familia {$given}"],
        ])->assertCreated()->json('data');

        return ['vivienda' => $data['vivienda']['id'], 'familia' => $data['familia']['id']];
    }

    private function cargos(string $familiaId): array
    {
        return Cargo::where('familia_id', $familiaId)->orderBy('month')->orderBy('concept')->pluck('id')->all();
    }

    public function test_paying_marks_cells_and_reduces_debt(): void
    {
        $grid = $this->getJson("/api/v1/cobranza/familias/{$this->ana['familia']}")->assertOk()->json('data');
        $this->assertSame(['Cuota de septiembre 2026', 'Cuota de octubre 2026', 'Multa: No asistió a la faena (octubre 2026)'], array_column($grid, 'label'));
        $this->assertSame(['por_pagar', 'por_pagar', 'por_pagar'], array_column($grid, 'status'));

        $pago = $this->postJson('/api/v1/pagos', ['familia_id' => $this->ana['familia'], 'cargo_ids' => [$grid[0]['id'], $grid[2]['id']]])
            ->assertCreated()
            ->assertJsonPath('data.folio', 'W-000001')
            ->assertJsonPath('data.total_cents', 25000)
            ->assertJsonCount(2, 'data.lines');

        $grid = $this->getJson("/api/v1/cobranza/familias/{$this->ana['familia']}")->json('data');
        $this->assertSame(['pagado', 'por_pagar', 'pagado'], array_column($grid, 'status'));
        $this->assertSame('W-000001', $grid[0]['folio']);

        // Lo ya pagado no se cobra dos veces; lo que falta sí.
        $this->postJson('/api/v1/pagos', ['familia_id' => $this->ana['familia'], 'cargo_ids' => [$grid[0]['id']]])
            ->assertStatus(409)->assertJsonPath('error.code', 'already_paid')
            ->assertJsonPath('error.message', 'Cuota de septiembre 2026 ya se pagó con el folio W-000001.');
        $this->postJson('/api/v1/pagos', ['familia_id' => $this->ana['familia'], 'cargo_ids' => [$grid[1]['id']]])->assertJsonPath('data.folio', 'W-000002');

        $this->getJson('/api/v1/cobranza/buscar?search=calle 1')->assertJsonPath('data.0.familias.0.owed_cents', 0);
        $this->getJson('/api/v1/cobranza/buscar?search=calle 2')->assertJsonPath('data.0.familias.0.owed_cents', 30000);
        $this->postJson("/api/v1/padron/familias/{$this->ana['familia']}/deactivate")->assertOk(); // ya no debe nada
        $log = AuditLog::where('action', 'pago.created')->first();
        $this->assertSame(['Cuota de septiembre 2026', 'Multa: No asistió a la faena (octubre 2026)'], $log->data['conceptos']);
    }

    public function test_cannot_pay_other_family_voided_or_partial_cargos(): void
    {
        [$sep] = $this->cargos($this->beto['familia']);
        $this->postJson('/api/v1/pagos', ['familia_id' => $this->ana['familia'], 'cargo_ids' => [$sep]])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_cargos');

        $this->postJson("/api/v1/movements/cargos/{$sep}/void", ['reason' => 'Exenta por acuerdo'])->assertOk();
        $this->postJson('/api/v1/pagos', ['familia_id' => $this->beto['familia'], 'cargo_ids' => [$sep]])
            ->assertStatus(409)->assertJsonPath('error.code', 'cargo_voided');

        // Desde un equipo, cobrar menos del monto se rechaza.
        [, $oct] = $this->cargos($this->beto['familia']);
        $this->postJson('/api/v1/sync/push', ['device_id' => $this->deviceId, 'mutations' => [[
            'mutation_id' => (string) Str::uuid(), 'entity' => 'pagos', 'entity_id' => (string) Str::uuid(), 'op' => 'create',
            'payload' => ['familia_id' => $this->beto['familia'], 'folio' => 'E01-000001', 'lines' => [['cargo_id' => $oct, 'amount_cents' => 10000]]],
        ]]])->assertJsonPath('data.results.0.code', 'partial_payment')
            ->assertJsonPath('data.results.0.message', 'Cuota de octubre 2026 se paga completo: $150.00.');
    }

    public function test_cobrador_collects_any_titular_offline(): void
    {
        Sanctum::actingAs($this->cobrador);
        $anaCargos = $this->cargos($this->ana['familia']);
        $push = fn (string $familia, array $cargos, string $folio, ?string $id = null) => $this->postJson('/api/v1/sync/push', ['device_id' => $this->deviceId, 'mutations' => [[
            'mutation_id' => (string) Str::uuid(), 'entity' => 'pagos', 'entity_id' => $id ?? (string) Str::uuid(), 'op' => 'create',
            'payload' => ['familia_id' => $familia, 'folio' => $folio, 'paid_at' => '2026-10-15T09:30:00-06:00',
                'lines' => array_map(fn ($c) => ['cargo_id' => $c, 'amount_cents' => Cargo::find($c)->amount_cents], $cargos)],
        ]]]);

        $id = (string) Str::uuid();
        $push($this->ana['familia'], $anaCargos, 'E01-000001', $id)->assertJsonPath('data.results.0.status', 'accepted');
        $pago = Pago::findOrFail($id);
        $this->assertSame($this->cobrador->id, $pago->cobrador_id);
        $this->assertSame(40000, $pago->total_cents);

        // Folio repetido o de otro equipo: rechazados. A cualquier titular sí le cobra.
        $push($this->ana['familia'], [], 'E01-000001')->assertJsonPath('data.results.0.status', 'rejected');
        $push($this->ana['familia'], $anaCargos, 'E09-000001')->assertJsonPath('data.results.0.code', 'invalid_folio');
        $push($this->beto['familia'], $this->cargos($this->beto['familia']), 'E01-000002')->assertJsonPath('data.results.0.status', 'accepted');

        // Otro equipo cobró lo mismo sin conexión: se rechaza para no cobrar dos veces.
        $push($this->ana['familia'], [$anaCargos[0]], 'E01-000003')->assertJsonPath('data.results.0.code', 'already_paid');

        // El cobrador descarga los pagos de todos y ve a todos los titulares.
        $pull = collect($this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->json('data.changes'));
        $this->assertCount(2, $pull->where('entity', 'pagos')->pluck('entity_id')->unique());
        $this->getJson('/api/v1/cobranza/buscar')->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/cobranza/familias/{$this->beto['familia']}")->assertOk();

        // Solo el administrador anula; los cargos vuelven a quedar por pagar.
        $this->postJson("/api/v1/movements/pagos/{$id}/void", ['reason' => 'Billete falso'])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/movements/pagos/{$id}/void", ['reason' => 'Billete falso'])->assertOk();
        $this->assertSame(['por_pagar', 'por_pagar', 'por_pagar'], array_column($this->getJson("/api/v1/cobranza/familias/{$this->ana['familia']}")->json('data'), 'status'));
        $this->assertSame('void', SyncChange::where('entity', 'pagos')->latest('id')->value('op'));
    }

    public function test_unpaid_periods_get_the_community_fine_after_the_last_pay_day(): void
    {
        $this->patchJson('/api/v1/ajustes', ['multa_cents' => 5000, 'dias_pago' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20]])->assertOk()
            ->assertJsonPath('data.multa_label', '$50.00')
            ->assertJsonPath('data.dias_pago.19', 20);

        // Hoy 15 de octubre: septiembre ya venció (se pagaba hasta el 20 de septiembre); octubre todavía no.
        app(CobroAutomatico::class)->run($this->community->fresh());
        $multas = Cargo::where('familia_id', $this->beto['familia'])->where('concept', 'multa')->get();
        $this->assertCount(1, $multas);
        $this->assertSame(5000, $multas[0]->amount_cents);
        $this->assertSame('No pagó septiembre 2026 a tiempo', $multas[0]->description);

        // No se repite.
        app(CobroAutomatico::class)->run($this->community->fresh());
        $this->assertCount(1, Cargo::where('familia_id', $this->beto['familia'])->where('concept', 'multa')->get());

        // Periodo con periodo se va sumando; noviembre se generó solo.
        $this->travelTo('2026-11-21 08:00:00');
        app(CobroAutomatico::class)->run($this->community->fresh());
        $this->assertCount(3, Cargo::where('familia_id', $this->beto['familia'])->where('concept', 'multa')->get());
        $this->assertTrue(Cargo::where('familia_id', $this->beto['familia'])->where('month', 11)->where('concept', 'cuota')->exists());
    }

    public function test_bimonthly_collection_and_pay_days_rule(): void
    {
        // Septiembre–octubre ya se cobró: la cuota nueva aplica desde noviembre.
        $this->patchJson('/api/v1/ajustes', ['periodicidad_meses' => 2, 'cuota_cents' => 28000, 'dias_pago' => [5, 20], 'cobro_fuera_de_dias' => false])->assertOk()
            ->assertJsonPath('data.cuota_cents', 15000);

        // Noviembre–diciembre es un solo periodo.
        $this->travelTo('2026-11-05 09:00:00');
        app(CobroAutomatico::class)->run($this->community->fresh());
        $nov = Cargo::where('familia_id', $this->ana['familia'])->where('month', 11)->firstOrFail();
        $this->assertSame(2, $nov->meses);
        $this->assertSame(28000, $nov->amount_cents);
        $this->assertSame('Cuota de noviembre–diciembre 2026', CobranzaService::concept($nov));

        // El 5 es día de cobro; el 6 no, y la comunidad no cobra fuera de sus días.
        $this->postJson('/api/v1/pagos', ['familia_id' => $this->ana['familia'], 'cargo_ids' => [$nov->id]])->assertCreated();
        $this->travelTo('2026-11-06 09:00:00');
        [$sep] = $this->cargos($this->beto['familia']);
        $this->postJson('/api/v1/pagos', ['familia_id' => $this->beto['familia'], 'cargo_ids' => [$sep]])
            ->assertStatus(422)->assertJsonPath('error.code', 'not_pay_day')
            ->assertJsonPath('error.message', 'Hoy no es día de cobro. Se cobra los días 5 y 20 de cada mes.');
    }

    public function test_special_cuota_and_paying_months_ahead_up_to_the_limit(): void
    {
        $this->patchJson("/api/v1/padron/familias/{$this->beto['familia']}", ['cuota_especial_cents' => 30000])->assertOk();
        $this->patchJson('/api/v1/ajustes', ['meses_adelanto' => 2])->assertOk();
        // Se cobra cada mes: 2 periodos por adelantado = noviembre y diciembre.

        // Noviembre y diciembre por adelantado, con la cuota especial.
        $pago = $this->postJson('/api/v1/pagos', ['familia_id' => $this->beto['familia'], 'cargo_ids' => [],
            'advance' => [['year' => 2026, 'month' => 11], ['year' => 2026, 'month' => 12]]])
            ->assertCreated()->assertJsonPath('data.total_cents', 60000);
        $this->assertCount(2, $pago->json('data.lines'));

        $this->postJson('/api/v1/pagos', ['familia_id' => $this->beto['familia'], 'cargo_ids' => [], 'advance' => [['year' => 2027, 'month' => 1]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'too_far_ahead')
            ->assertJsonPath('error.message', 'Solo se pueden pagar 2 periodo(s) por adelantado.');

        // Al llegar noviembre no se duplica: ya estaba pagado.
        $this->travelTo('2026-11-02 08:00:00');
        app(CobroAutomatico::class)->run($this->community->fresh());
        $this->assertSame(1, Cargo::where('familia_id', $this->beto['familia'])->where('month', 11)->where('concept', 'cuota')->count());
        $this->assertSame('pagado', collect($this->getJson("/api/v1/cobranza/familias/{$this->beto['familia']}")->json('data'))
            ->firstWhere('month', 11)['status']);
    }

    public function test_receipt_keeps_the_names_of_the_cobradores_of_that_moment(): void
    {
        [$sep] = $this->cargos($this->beto['familia']);
        $pago = $this->postJson('/api/v1/pagos', ['familia_id' => $this->beto['familia'], 'cargo_ids' => [$sep]])->assertCreated();
        $this->assertSame('José Cobrador', $pago->json('data.cobradores'));
        $this->getJson('/api/v1/ajustes')->assertJsonPath('data.cobradores', ['José Cobrador']);
    }
}
