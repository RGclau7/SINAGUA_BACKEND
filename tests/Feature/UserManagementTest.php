<?php

namespace Tests\Feature;

use App\Auth\Assignments;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Roles;
use App\Models\SyncChange;
use App\Models\User;
use App\Users\UserService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CDU25 a CDU28 por la API REST.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        Sanctum::actingAs($this->admin);
        $this->useCommunity($this->community);
    }

    private function newUser(array $overrides = [])
    {
        return $this->postJson('/api/v1/users', [
            'given_names' => 'Juan',
            'paternal_surname' => 'Pérez',
            'maternal_surname' => 'Gómez',
            'phone' => '415 555 0101',
            'address' => 'Calle del Pozo 3, San Isidro',
            'email' => 'juan@sanisidro.mx',
            'role' => Roles::COBRADOR,
            'password' => 'clave-segura-123',
            ...$overrides,
        ]);
    }

    private function asCobradorWithViviendas(int $count): void
    {
        $this->app->instance(Assignments::class, new class($count) implements Assignments
        {
            public function __construct(private int $count) {}

            public function isAssigned(User $cobrador, string $viviendaId): bool
            {
                return true;
            }

            public function countAssigned(User $cobrador): int
            {
                return $this->count;
            }
        });
    }

    public function test_admin_registers_user_with_role(): void
    {
        $id = $this->newUser()
            ->assertCreated()
            ->assertJsonPath('data.role', Roles::COBRADOR)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password')
            ->json('data.id');

        $user = User::findOrFail($id);
        $this->assertSame($this->community->id, $user->community_id);
        $this->assertTrue(AuditLog::where('action', 'user.created')->where('entity_id', $id)->exists());

        // Queda en el feed para que los demás equipos lo reciban, sin la contraseña.
        $change = SyncChange::where('entity', 'usuarios')->where('entity_id', $id)->firstOrFail();
        $this->assertArrayNotHasKey('password', $change->payload);

        // Puede iniciar sesión.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'juan@sanisidro.mx', 'password' => 'clave-segura-123'])
            ->assertOk()->assertJsonPath('data.user.panel', Roles::COBRADOR);
    }

    public function test_invalid_or_duplicate_user_is_rejected(): void
    {
        $this->newUser()->assertCreated();

        $this->newUser(['given_names' => 'Otro'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.email.0', 'Ese correo ya está registrado.');
        $this->newUser(['email' => 'nuevo@x.mx', 'role' => Roles::SUPERADMIN])->assertUnprocessable();
        $this->newUser(['email' => 'nuevo@x.mx', 'password' => 'corta'])->assertUnprocessable();
    }

    public function test_registration_requires_full_personal_data(): void
    {
        $this->postJson('/api/v1/users', ['email' => 'nuevo@x.mx', 'role' => Roles::COBRADOR, 'password' => 'clave-segura-123'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.given_names.0', 'El nombre es obligatorio.')
            ->assertJsonPath('error.details.paternal_surname.0', 'El apellido paterno es obligatorio.')
            ->assertJsonPath('error.details.phone.0', 'El teléfono es obligatorio.')
            ->assertJsonPath('error.details.address.0', 'La dirección es obligatoria.');

        $this->newUser(['email' => 'nuevo@x.mx', 'phone' => '12345'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.phone.0', 'El teléfono debe tener 10 dígitos.');
    }

    public function test_phone_is_normalized_and_full_name_is_built(): void
    {
        $this->newUser(['phone' => '+52 (415) 555-0101', 'maternal_surname' => ''])
            ->assertCreated()
            ->assertJsonPath('data.phone', '4155550101')
            ->assertJsonPath('data.name', 'Juan Pérez')
            ->assertJsonPath('data.maternal_surname', null)
            ->assertJsonPath('data.address', 'Calle del Pozo 3, San Isidro');
    }

    public function test_only_users_with_permission_manage_users(): void
    {
        $cobrador = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR);
        Sanctum::actingAs($cobrador);

        $this->getJson('/api/v1/users')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->newUser()->assertForbidden();
    }

    public function test_list_is_paginated_searchable_and_limited_to_community(): void
    {
        User::factory()->count(24)->forCommunity($this->community)->create();
        User::factory()->forCommunity($this->community)->create(['name' => 'Xóchitl Zacarías']);
        $outsider = User::factory()->create(['name' => 'Xóchitl de otra comunidad']);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/users?search=xóchitl')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Xóchitl Zacarías');

        $this->getJson("/api/v1/users/{$outsider->id}")->assertNotFound();
        $this->patchJson("/api/v1/users/{$outsider->id}", ['given_names' => 'X'])->assertNotFound();
    }

    public function test_admin_edits_data_and_role(): void
    {
        $id = $this->newUser()->json('data.id');

        $this->patchJson("/api/v1/users/{$id}", ['maternal_surname' => 'López', 'phone' => '4159998877', 'role' => Roles::ADMIN])
            ->assertOk()
            ->assertJsonPath('data.name', 'Juan Pérez López')
            ->assertJsonPath('data.phone', '4159998877')
            ->assertJsonPath('data.role', Roles::ADMIN);

        $log = AuditLog::where('action', 'user.updated')->where('entity_id', $id)->firstOrFail();
        $this->assertSame(Roles::ADMIN, $log->data['changes']['role']['to']);
    }

    public function test_password_change_closes_open_sessions(): void
    {
        $id = $this->newUser()->json('data.id');
        $user = User::findOrFail($id);
        $user->createToken('escritorio');

        $this->patchJson("/api/v1/users/{$id}", ['password' => 'otra-clave-segura'])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'user.updated')->latest('id')->first()->data['changes']['password_changed']);
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['role' => Roles::COBRADOR])
            ->assertStatus(422)->assertJsonPath('error.code', 'cannot_change_own_role');
        $this->postJson("/api/v1/users/{$this->admin->id}/deactivate")
            ->assertStatus(422)->assertJsonPath('error.code', 'cannot_deactivate_self');
    }

    public function test_community_keeps_at_least_one_active_admin(): void
    {
        // Defensa en el servicio: si el único administrador activo es el afectado, se rechaza
        // (por ejemplo, una mutación sincronizada por un administrador que ya fue desactivado).
        $this->admin->forceFill(['is_active' => false])->save();
        $onlyActiveAdmin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        $service = app(UserService::class);

        try {
            $service->deactivate($this->admin, $onlyActiveAdmin);
            $this->fail('Debió rechazar la desactivación del último administrador activo.');
        } catch (ApiException $e) {
            $this->assertSame('last_admin', $e->errorCode);
        }

        $this->expectExceptionObject(new ApiException('last_admin', 'La comunidad debe conservar al menos un administrador activo.', 422));
        $service->update($this->admin, $onlyActiveAdmin, ['role' => Roles::COBRADOR]);
    }

    public function test_deactivate_revokes_tokens_and_activate_restores_access(): void
    {
        $id = $this->newUser()->json('data.id');
        $user = User::findOrFail($id);
        $user->createToken('escritorio');

        $this->postJson("/api/v1/users/{$id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('notices', []);
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson("/api/v1/users/{$id}/deactivate")->assertStatus(409)->assertJsonPath('error.code', 'already_inactive');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'juan@sanisidro.mx', 'password' => 'clave-segura-123'])
            ->assertJsonPath('error.code', 'account_inactive');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/users/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson("/api/v1/users/{$id}/activate")->assertStatus(409)->assertJsonPath('error.code', 'already_active');

        $this->assertSame(1, AuditLog::where('action', 'user.deactivated')->count());
        $this->assertSame(1, AuditLog::where('action', 'user.activated')->count());
    }

    public function test_deactivating_cobrador_with_viviendas_warns_to_reassign(): void
    {
        $this->asCobradorWithViviendas(3);
        $id = $this->newUser()->json('data.id');

        $this->postJson("/api/v1/users/{$id}/deactivate")
            ->assertOk()
            ->assertJsonPath('notices.0.code', 'reassign_viviendas')
            ->assertJsonPath('notices.0.message', 'El cobrador tiene 3 vivienda(s) asignada(s); deben reasignarse a otro cobrador.');
    }

    public function test_roles_endpoint_lists_assignable_roles_with_permissions(): void
    {
        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.name', Roles::COBRADOR);
    }

    public function test_new_community_admin_is_published_to_feed(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $communityId = $this->postJson('/api/v1/admin/communities', [
            'name' => 'El Salto',
            'state' => 'Guanajuato',
            'municipality' => 'San Miguel de Allende',
            'locality' => 'El Salto, calle del pozo s/n',
            'contact_phone' => '415 123 4567',
            'admin' => [
                'given_names' => 'Felipe',
                'paternal_surname' => 'Ramírez',
                'maternal_surname' => 'Soto',
                'phone' => '(415) 222-3344',
                'address' => 'Calle Hidalgo 5, El Salto',
                'email' => 'Felipe@ElSalto.mx',
                'password' => 'una-clave-segura',
            ],
        ])->json('data.id');

        $this->useCommunity(Community::find($communityId));
        $this->assertSame(1, SyncChange::where('entity', 'usuarios')->count());
        $this->assertSame('felipe@elsalto.mx', SyncChange::where('entity', 'usuarios')->first()->payload['email']);
    }

    public function test_user_ids_are_uuid_routes(): void
    {
        $this->getJson('/api/v1/users/'.Str::uuid())->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
}
