<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Roles;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_superadmin_creates_community_with_first_admin(): void
    {
        $super = User::factory()->superAdmin()->create();
        Sanctum::actingAs($super);

        $response = $this->postJson('/api/v1/admin/communities', [
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
        ])->assertCreated()
            ->assertJsonPath('data.name', 'El Salto')
            ->assertJsonPath('data.is_active', true);

        $community = Community::findOrFail($response->json('data.id'));
        $admin = User::where('email', 'felipe@elsalto.mx')->firstOrFail();
        $this->assertSame('Felipe Ramírez Soto', $admin->name);
        $this->assertSame('4152223344', $admin->phone);
        $this->assertSame('4151234567', $community->contact_phone);
        $this->assertSame('San Miguel de Allende', $community->municipality);
        $this->assertSame($community->id, $admin->community_id);
        $this->assertTrue($admin->hasRole(Roles::ADMIN));

        $this->useCommunity($community);
        $log = AuditLog::where('action', 'community.created')->firstOrFail();
        $this->assertSame($super->id, $log->user_id);

        // El nuevo administrador trabaja dentro de su comunidad.
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/devices', ['id' => (string) Str::uuid(), 'name' => 'Caja'])
            ->assertCreated()
            ->assertJsonPath('data.community_id', $community->id);
    }

    public function test_community_requires_its_data_and_a_complete_admin(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $details = $this->postJson('/api/v1/admin/communities', ['admin' => ['email' => 'x@x.mx']])
            ->assertUnprocessable()
            ->json('error.details');

        $this->assertSame('El nombre de la comunidad es obligatorio.', $details['name'][0]);
        $this->assertSame('El estado es obligatorio.', $details['state'][0]);
        $this->assertSame('El municipio es obligatorio.', $details['municipality'][0]);
        $this->assertSame('La localidad o dirección es obligatoria.', $details['locality'][0]);
        $this->assertSame('El teléfono de contacto es obligatorio.', $details['contact_phone'][0]);
        $this->assertSame('El nombre es obligatorio.', $details['admin.given_names'][0]);
        $this->assertSame('El apellido paterno es obligatorio.', $details['admin.paternal_surname'][0]);
        $this->assertSame('El teléfono es obligatorio.', $details['admin.phone'][0]);
        $this->assertSame('La dirección es obligatoria.', $details['admin.address'][0]);
        $this->assertSame('La contraseña del administrador es obligatoria.', $details['admin.password'][0]);

        $this->assertSame(0, Community::count());
    }

    public function test_superadmin_cannot_access_community_data(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/sync/pull?device_id='.Str::uuid())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'community_required');
        $this->postJson('/api/v1/devices', ['id' => (string) Str::uuid(), 'name' => 'Caja'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'community_required');
    }

    public function test_superadmin_list_shows_communities_without_movements(): void
    {
        Community::factory()->count(2)->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/admin/communities')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'is_active']]]);
    }

    public function test_community_users_cannot_manage_communities(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/communities')->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->postJson('/api/v1/admin/communities', ['name' => 'X'])->assertForbidden();
        $this->assertSame(1, Community::count());
    }

    public function test_cli_command_creates_superadmin(): void
    {
        $this->artisan('sinagua:superadmin', ['email' => 'root@sinagua.mx', 'name' => 'Comité'])
            ->expectsQuestion('Contraseña (mínimo 10 caracteres)', 'una-clave-segura')
            ->assertSuccessful();

        $user = User::where('email', 'root@sinagua.mx')->firstOrFail();
        $this->assertNull($user->community_id);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertFalse(app(CurrentCommunity::class)->isSystem());
    }
}
