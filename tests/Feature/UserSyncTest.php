<?php

namespace Tests\Feature;

use App\Auth\Assignments;
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
 * Gestión de usuarios desde el escritorio, quizá sin conexión, vía /sync.
 */
class UserSyncTest extends TestCase
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

    private function push(array $mutation)
    {
        return $this->postJson('/api/v1/sync/push', [
            'device_id' => $this->deviceId,
            'mutations' => [['mutation_id' => (string) Str::uuid(), 'entity' => 'usuarios', ...$mutation]],
        ]);
    }

    private function createViaSync(array $payload = []): string
    {
        $id = (string) Str::uuid();
        $this->push([
            'entity_id' => $id,
            'op' => 'create',
            'payload' => [
                'given_names' => 'Lupita',
                'paternal_surname' => 'Hernández',
                'phone' => '4151230000',
                'address' => 'Calle Morelos 7, San Isidro',
                'email' => 'lupita@sanisidro.mx',
                'role' => Roles::COBRADOR,
                // Hash generado en el equipo: la contraseña nunca viaja ni se guarda en claro.
                'password_hash' => password_hash('clave-del-equipo', PASSWORD_BCRYPT, ['cost' => 10]),
                ...$payload,
            ],
        ])->assertJsonPath('data.results.0.status', 'accepted');

        return $id;
    }

    public function test_user_created_offline_keeps_client_id_and_can_log_in(): void
    {
        $id = $this->createViaSync();

        $user = User::findOrFail($id);
        $this->assertSame($this->community->id, $user->community_id);
        $this->assertTrue($user->hasRole(Roles::COBRADOR));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'lupita@sanisidro.mx', 'password' => 'clave-del-equipo'])->assertOk();
        // Al entrar se re-hashea con el costo del servidor.
        $this->assertFalse(password_needs_rehash($user->fresh()->password, PASSWORD_BCRYPT, ['cost' => (int) config('hashing.bcrypt.rounds')]));
    }

    public function test_plain_password_or_bad_hash_is_rejected_via_sync(): void
    {
        $this->push([
            'entity_id' => (string) Str::uuid(),
            'op' => 'create',
            'payload' => ['given_names' => 'X', 'paternal_surname' => 'Y', 'phone' => '4150000000', 'address' => 'Calle 1, pueblo',
                'email' => 'x@x.mx', 'role' => Roles::COBRADOR, 'password_hash' => 'en-claro'],
        ])->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'validation_error');
    }

    public function test_users_feed_is_visible_to_admin_but_not_to_cobrador(): void
    {
        $id = $this->createViaSync();

        $admin = $this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->assertOk();
        $this->assertSame('all', $admin->json('data.scope'));
        $userChange = collect($admin->json('data.changes'))->firstWhere('entity', 'usuarios');
        $this->assertSame($id, $userChange['entity_id']);
        $this->assertArrayNotHasKey('password_hash', $userChange['payload']);

        Sanctum::actingAs(User::findOrFail($id));
        $cobrador = $this->getJson("/api/v1/sync/pull?device_id={$this->deviceId}")->assertOk();
        // Solo los ajustes de cobro (con los nombres de los cobradores para el recibo); ningún usuario.
        $this->assertSame(['ajustes'], collect($cobrador->json('data.changes'))->pluck('entity')->unique()->values()->all());
        // El cobrador no ve usuarios, bitácora ni cuotas; sí todo el padrón, cargos y pagos (cobra a todos).
        $this->assertStringStartsWith('hidden:bitacora,categorias_ingreso,cuotas,ingresos,periodos,usuarios|restricted:asignaciones:', $cobrador->json('data.scope'));
    }

    public function test_cobrador_cannot_manage_users_via_sync(): void
    {
        $cobrador = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR);
        Sanctum::actingAs($cobrador);

        $this->push([
            'entity_id' => $this->admin->id,
            'op' => 'update',
            'payload' => ['role' => Roles::COBRADOR],
        ])->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'forbidden');

        $this->assertTrue($this->admin->fresh()->hasRole(Roles::ADMIN));
    }

    public function test_stale_edit_loses_to_server_version(): void
    {
        $id = $this->createViaSync();
        $base = User::findOrFail($id)->updated_at->toIso8601String();

        $this->travel(5)->minutes();
        $this->push(['entity_id' => $id, 'op' => 'update', 'payload' => ['maternal_surname' => 'G.', 'base_updated_at' => $base]])
            ->assertJsonPath('data.results.0.status', 'accepted');

        // Otro equipo editó sin conocer el cambio anterior.
        $this->deviceId = (string) Str::uuid();
        Device::create(['id' => $this->deviceId, 'name' => 'Caja 2', 'receipt_prefix' => 'E02']);
        $this->push(['entity_id' => $id, 'op' => 'update', 'payload' => ['given_names' => 'Guadalupe', 'base_updated_at' => $base]])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'conflict');

        $this->assertSame('Lupita Hernández G.', User::findOrFail($id)->name);
    }

    public function test_consecutive_offline_edits_from_same_device_do_not_conflict(): void
    {
        $id = $this->createViaSync();
        $base = User::findOrFail($id)->updated_at->toIso8601String();
        $this->travel(5)->minutes();

        // Dos ediciones hechas sin red en el mismo equipo, ambas sobre la misma versión base.
        $this->postJson('/api/v1/sync/push', [
            'device_id' => $this->deviceId,
            'mutations' => [
                ['mutation_id' => (string) Str::uuid(), 'entity' => 'usuarios', 'entity_id' => $id, 'op' => 'update',
                    'payload' => ['maternal_surname' => 'G.', 'base_updated_at' => $base]],
                ['mutation_id' => (string) Str::uuid(), 'entity' => 'usuarios', 'entity_id' => $id, 'op' => 'update',
                    'payload' => ['maternal_surname' => 'García', 'base_updated_at' => $base]],
            ],
        ])->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.1.status', 'accepted');

        $this->assertSame('Lupita Hernández García', User::findOrFail($id)->name);
    }

    public function test_deactivation_via_sync_revokes_tokens_and_warns_about_viviendas(): void
    {
        $this->app->instance(Assignments::class, new class implements Assignments
        {
            public function isAssigned(User $cobrador, string $viviendaId): bool
            {
                return true;
            }

            public function countAssigned(User $cobrador): int
            {
                return 2;
            }
        });
        $id = $this->createViaSync();
        User::findOrFail($id)->createToken('escritorio');

        $this->push(['entity_id' => $id, 'op' => 'update', 'payload' => ['is_active' => false]])
            ->assertJsonPath('data.results.0.status', 'accepted')
            ->assertJsonPath('data.results.0.notices.0.code', 'reassign_viviendas');

        $this->assertFalse(User::findOrFail($id)->is_active);
        $this->assertSame(0, User::findOrFail($id)->tokens()->count());
    }

    public function test_offline_registration_requires_full_personal_data(): void
    {
        $this->push([
            'entity_id' => (string) Str::uuid(),
            'op' => 'create',
            'payload' => ['given_names' => 'Sin', 'email' => 'incompleto@x.mx', 'role' => Roles::COBRADOR,
                'password_hash' => password_hash('clave-del-equipo', PASSWORD_BCRYPT, ['cost' => 10])],
        ])->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'validation_error')
            ->assertJsonPath('data.results.0.message', 'El apellido paterno es obligatorio. El teléfono es obligatorio. La dirección es obligatoria.');
    }

    public function test_users_are_never_deleted(): void
    {
        $id = $this->createViaSync();

        $this->push(['entity_id' => $id, 'op' => 'delete', 'payload' => []])
            ->assertJsonPath('data.results.0.code', 'not_supported');
        $this->assertNotNull(User::find($id));
    }

    public function test_cannot_edit_user_of_other_community(): void
    {
        $outsider = User::factory()->create();

        $this->push(['entity_id' => $outsider->id, 'op' => 'update', 'payload' => ['given_names' => 'Hackeado']])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'not_found');
        $this->assertNotSame('Hackeado', $outsider->fresh()->given_names);
    }
}
