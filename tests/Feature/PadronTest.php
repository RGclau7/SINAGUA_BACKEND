<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Familia;
use App\Models\Habitante;
use App\Models\Roles;
use App\Models\SyncChange;
use App\Models\User;
use App\Models\Vivienda;
use App\Padron\Debts;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Padrón por la API (CDU3 a CDU5): alta de familia con titular y vivienda, varias familias
 * por domicilio, búsqueda paginada y baja lógica con aviso de adeudo.
 */
class PadronTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->community = Community::factory()->create(['name' => 'San Isidro']);
        $this->admin = User::factory()->forCommunity($this->community)->create(['name' => 'Ana Admin'])->assignRole(Roles::ADMIN);
        $this->useCommunity($this->community);
        Sanctum::actingAs($this->admin);
    }

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/v1/padron/registro', array_replace_recursive([
            'vivienda' => ['address' => 'Calle Hidalgo 10', 'neighborhood' => 'Barrio Alto'],
            'titular' => ['given_names' => 'José', 'paternal_surname' => 'López', 'maternal_surname' => 'Ruiz', 'phone' => '415 123 4567'],
        ], $overrides));
    }

    public function test_registers_family_with_titular_and_new_vivienda(): void
    {
        $response = $this->register()->assertCreated()
            ->assertJsonPath('data.vivienda.address', 'Calle Hidalgo 10')
            ->assertJsonPath('data.familia.name', 'López Ruiz')
            ->assertJsonPath('data.titular.name', 'José López Ruiz')
            ->assertJsonPath('data.titular.phone', '4151234567');

        $familia = Familia::findOrFail($response->json('data.familia.id'));
        $this->assertSame($response->json('data.titular.id'), $familia->head_id);
        $this->assertSame($this->community->id, $familia->community_id);
        $this->assertSame($familia->vivienda_id, $response->json('data.vivienda.id'));

        // Bitácora en lenguaje de la API y feed para los equipos.
        $this->assertEqualsCanonicalizing(
            ['vivienda.created', 'familia.created', 'habitante.created', 'familia.updated'],
            AuditLog::pluck('action')->unique()->values()->all(),
        );
        $this->assertSame(3, SyncChange::whereIn('entity', ['viviendas', 'familias', 'habitantes'])->count());
    }

    public function test_titular_phone_is_optional_but_must_have_ten_digits(): void
    {
        $this->register(['titular' => ['phone' => null]])->assertCreated();
        $this->register(['vivienda' => ['address' => 'Calle Morelos 4'], 'titular' => ['phone' => '12345']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_second_family_at_same_address_needs_confirmation(): void
    {
        $first = $this->register()->assertCreated();

        // Mismo domicilio escrito de otra forma.
        $this->register([
            'vivienda' => ['address' => 'calle  HIDALGO 10', 'neighborhood' => 'barrio alto'],
            'titular' => ['given_names' => 'Rosa', 'paternal_surname' => 'Martínez', 'maternal_surname' => null],
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'address_has_family')
            ->assertJsonPath('error.details.familias', ['López Ruiz']);

        $second = $this->register([
            'vivienda' => ['address' => 'calle  HIDALGO 10', 'neighborhood' => 'barrio alto'],
            'titular' => ['given_names' => 'Rosa', 'paternal_surname' => 'Martínez', 'maternal_surname' => null],
            'confirm_shared_address' => true,
        ])->assertCreated();

        $this->assertSame($first->json('data.vivienda.id'), $second->json('data.vivienda.id'));
        $this->assertSame(1, Vivienda::count());
        $this->assertSame(2, Familia::where('vivienda_id', $first->json('data.vivienda.id'))->count());
    }

    public function test_search_is_paginated_by_twenty(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->register([
                'vivienda' => ['address' => "Calle Juárez {$i}", 'neighborhood' => null],
                'titular' => ['given_names' => "Persona {$i}", 'paternal_surname' => 'García', 'maternal_surname' => null],
            ])->assertCreated();
        }

        $this->getJson('/api/v1/padron/familias')->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 23)
            ->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/padron/familias?page=2')->assertJsonCount(3, 'data');

        // Busca por titular, por domicilio y por teléfono.
        $this->getJson('/api/v1/padron/familias?search=persona 7')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.head_name', 'Persona 7 García')
            ->assertJsonPath('data.0.vivienda_label', 'Calle Juárez 7')
            ->assertJsonPath('data.0.integrantes_activos', 1);
        $this->getJson('/api/v1/padron/viviendas?search=juárez 2')->assertJsonPath('meta.total', 5); // 2, 20, 21, 22, 23
        $this->getJson('/api/v1/padron/habitantes?search=nadie')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.message', 'No hay registros que coincidan con la búsqueda.');
    }

    public function test_adds_member_and_changes_titular(): void
    {
        $familiaId = $this->register()->json('data.familia.id');

        $maria = $this->postJson('/api/v1/padron/habitantes', [
            'familia_id' => $familiaId, 'given_names' => 'María', 'paternal_surname' => 'López', 'phone' => '',
        ])->assertCreated()->assertJsonPath('data.phone', null);

        $this->patchJson("/api/v1/padron/familias/{$familiaId}", ['head_id' => $maria->json('data.id')])
            ->assertOk()->assertJsonPath('data.head_id', $maria->json('data.id'));

        $log = AuditLog::where('action', 'familia.updated')->latest('id')->first();
        $this->assertSame(['from' => 'José López Ruiz', 'to' => 'María López'], $log->data['changes']['head']);

        // El titular debe ser de la familia.
        $other = $this->register(['vivienda' => ['address' => 'Calle Morelos 4'], 'titular' => ['given_names' => 'Pedro']])->json('data.titular.id');
        $this->patchJson("/api/v1/padron/familias/{$familiaId}", ['head_id' => $other])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_head');
    }

    public function test_deactivating_vivienda_cascades_and_reactivating_brings_them_back(): void
    {
        $data = $this->register()->json('data');
        $this->postJson('/api/v1/padron/habitantes', ['familia_id' => $data['familia']['id'], 'given_names' => 'Ana', 'paternal_surname' => 'López'])->assertCreated();

        $this->postJson("/api/v1/padron/viviendas/{$data['vivienda']['id']}/deactivate", ['reason' => 'Se mudaron a otra comunidad'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.deactivation_reason', 'Se mudaron a otra comunidad')
            ->assertJsonPath('notices.0.code', 'familias_deactivated')
            ->assertJsonPath('notices.1.code', 'habitantes_deactivated');

        $this->assertFalse(Familia::findOrFail($data['familia']['id'])->is_active);
        $this->assertSame(0, Habitante::where('is_active', true)->count());
        // Nada se borra.
        $this->assertSame(2, Habitante::count());

        // Ya no aparecen en la búsqueda normal, sí con status=baja.
        $this->getJson('/api/v1/padron/familias')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/padron/familias?status=baja')->assertJsonPath('meta.total', 1);

        // No se puede agregar a una familia dada de baja.
        $this->postJson('/api/v1/padron/habitantes', ['familia_id' => $data['familia']['id'], 'given_names' => 'X', 'paternal_surname' => 'Y'])
            ->assertStatus(422)->assertJsonPath('error.code', 'parent_inactive');

        $this->postJson("/api/v1/padron/viviendas/{$data['vivienda']['id']}/activate")->assertOk();
        $this->assertTrue(Familia::findOrFail($data['familia']['id'])->is_active);
        $this->assertSame(2, Habitante::where('is_active', true)->count());
    }

    public function test_deactivating_titular_leaves_family_without_head(): void
    {
        $data = $this->register()->json('data');

        $this->postJson("/api/v1/padron/habitantes/{$data['titular']['id']}/deactivate")
            ->assertOk()
            ->assertJsonPath('notices.0.code', 'family_without_head');

        $this->assertNull(Familia::findOrFail($data['familia']['id'])->head_id);
        $this->postJson("/api/v1/padron/habitantes/{$data['titular']['id']}/deactivate")->assertStatus(409)->assertJsonPath('error.code', 'already_inactive');
    }

    public function test_deactivation_with_debt_asks_for_confirmation(): void
    {
        $this->app->instance(Debts::class, new class implements Debts
        {
            public function owedByFamilia(Familia $familia): int
            {
                return 35000;
            }

            public function owedByVivienda(Vivienda $vivienda): int
            {
                return 35000;
            }
        });
        $familiaId = $this->register()->json('data.familia.id');

        $this->postJson("/api/v1/padron/familias/{$familiaId}/deactivate")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'has_debt')
            ->assertJsonPath('error.details.amount_cents', 35000)
            ->assertJsonPath('error.message', 'La familia López Ruiz tiene un adeudo de $350.00. ¿Darla de baja de todos modos?');
        $this->assertTrue(Familia::findOrFail($familiaId)->is_active);

        $this->postJson("/api/v1/padron/familias/{$familiaId}/deactivate", ['confirm_debt' => true])
            ->assertOk()
            ->assertJsonPath('notices.0.code', 'had_debt');
        $this->assertSame(35000, AuditLog::where('action', 'familia.deactivated')->first()->data['debt_cents']);
    }

    public function test_cobrador_cannot_use_padron_and_other_community_is_invisible(): void
    {
        $familiaId = $this->register()->json('data.familia.id');

        $cobrador = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR);
        Sanctum::actingAs($cobrador);
        $this->getJson('/api/v1/padron/familias')->assertForbidden();

        $other = Community::factory()->create();
        $otherAdmin = User::factory()->forCommunity($other)->create()->assignRole(Roles::ADMIN);
        Sanctum::actingAs($otherAdmin);
        $this->useCommunity(null);
        $this->getJson('/api/v1/padron/familias')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/padron/familias/{$familiaId}")->assertNotFound();
        $this->postJson('/api/v1/padron/habitantes', ['familia_id' => $familiaId, 'given_names' => 'Intrusa', 'paternal_surname' => 'X'])
            ->assertNotFound();
    }
}
