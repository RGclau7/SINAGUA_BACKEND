<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Device;
use App\Models\Roles;
use App\Models\User;
use App\Tenancy\CurrentCommunity;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'clave-correcta-123';

    private Community $community;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->community = Community::factory()->create(['name' => 'San Isidro']);
        $this->user = User::factory()->forCommunity($this->community)->create([
            'email' => 'tesorero@sanisidro.mx',
            'password' => self::PASSWORD,
        ]);
        $this->user->assignRole(Roles::ADMIN);
    }

    private function login(string $email, string $password, ?string $deviceId = null)
    {
        return $this->postJson('/api/v1/auth/login', array_filter([
            'email' => $email,
            'password' => $password,
            'device_id' => $deviceId,
        ]));
    }

    /** Las peticiones de una prueba comparten el guard; se olvida para validar cada token de nuevo. */
    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_login_returns_token_role_panel_and_community(): void
    {
        $response = $this->login('Tesorero@SanIsidro.mx ', self::PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.idle_timeout_minutes', 30)
            ->assertJsonPath('data.user.panel', Roles::ADMIN)
            ->assertJsonPath('data.user.roles', [Roles::ADMIN])
            ->assertJsonPath('data.user.community.name', 'San Isidro');

        $this->bearer($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'tesorero@sanisidro.mx');

        $this->assertNotNull($response->json('data.expires_at'));
    }

    public function test_wrong_password_and_unknown_email_look_the_same(): void
    {
        $wrong = $this->login('tesorero@sanisidro.mx', 'otra-clave')->assertUnauthorized();
        $unknown = $this->login('nadie@sanisidro.mx', 'otra-clave')->assertUnauthorized();

        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertSame('invalid_credentials', $wrong->json('error.code'));
    }

    public function test_account_locks_for_15_minutes_after_5_failures(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->login('tesorero@sanisidro.mx', 'mala')->assertJsonPath('error.code', 'invalid_credentials');
        }
        $this->login('tesorero@sanisidro.mx', 'mala')
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'account_locked')
            ->assertJsonPath('error.details.retry_after_seconds', 900);

        // Ni la contraseña correcta entra durante el bloqueo.
        $this->travel(14)->minutes();
        $this->login('tesorero@sanisidro.mx', self::PASSWORD)->assertStatus(423);

        $this->travel(2)->minutes();
        $this->login('tesorero@sanisidro.mx', self::PASSWORD)->assertOk();
        $this->assertSame(0, $this->user->fresh()->failed_login_attempts);

        $this->useCommunity($this->community);
        $this->assertSame(1, AuditLog::where('action', 'auth.locked')->count());
        $this->assertSame(4, AuditLog::where('action', 'auth.failed')->count());
    }

    public function test_unknown_email_also_locks_so_accounts_cannot_be_enumerated(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->login('nadie@sanisidro.mx', 'mala')->assertUnauthorized();
        }
        $this->login('nadie@sanisidro.mx', 'mala')->assertStatus(423)->assertJsonPath('error.code', 'account_locked');
    }

    public function test_successful_login_resets_failure_count(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->login('tesorero@sanisidro.mx', 'mala');
        }
        $this->login('tesorero@sanisidro.mx', self::PASSWORD)->assertOk();

        $this->login('tesorero@sanisidro.mx', 'mala')->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->user->update(['is_active' => false]);

        $this->login('tesorero@sanisidro.mx', self::PASSWORD)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_inactive');

        // Con la contraseña mala no se revela que la cuenta está desactivada.
        $this->login('tesorero@sanisidro.mx', 'mala')->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_deactivated_user_loses_access_on_next_request(): void
    {
        $token = $this->login('tesorero@sanisidro.mx', self::PASSWORD)->json('data.token');

        $this->user->update(['is_active' => false]);

        $this->bearer($token)->getJson('/api/v1/sync/pull?device_id='.Str::uuid())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_inactive');
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_inactive_community_cannot_log_in(): void
    {
        $this->community->update(['is_active' => false]);

        $this->login('tesorero@sanisidro.mx', self::PASSWORD)->assertJsonPath('error.code', 'community_inactive');
    }

    public function test_token_expires_after_30_minutes_without_use(): void
    {
        $token = $this->login('tesorero@sanisidro.mx', self::PASSWORD)->json('data.token');

        $this->travel(20)->minutes();
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertOk();

        // 40 min desde el login pero solo 20 desde el último uso: sigue vigente.
        $this->travel(20)->minutes();
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(31)->minutes();
        $this->bearer($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_token_has_maximum_lifetime_even_if_used(): void
    {
        $token = $this->login('tesorero@sanisidro.mx', self::PASSWORD)->json('data.token');

        for ($i = 0; $i < 12 * 3; $i++) {
            $this->travel(20)->minutes();
            $this->bearer($token)->getJson('/api/v1/auth/me');
        }
        $this->travel(20)->minutes();

        $this->bearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_token(): void
    {
        $token = $this->login('tesorero@sanisidro.mx', self::PASSWORD)->json('data.token');

        $this->bearer($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->useCommunity($this->community);
        $this->assertTrue(AuditLog::where('action', 'auth.logout')->exists());
    }

    public function test_account_can_log_in_on_a_device_of_another_community(): void
    {
        $other = Community::factory()->create();
        $deviceId = (string) Str::uuid();
        $this->useCommunity($other);
        Device::create(['id' => $deviceId, 'name' => 'Caja ajena', 'receipt_prefix' => 'E01']);

        // Entra; el equipo se registra de nuevo en su comunidad con otro identificador.
        $this->login('tesorero@sanisidro.mx', self::PASSWORD, $deviceId)->assertOk();
    }

    public function test_superadmin_can_log_in_on_any_community_device(): void
    {
        User::factory()->superAdmin()->create(['email' => 'root@x.mx', 'password' => self::PASSWORD]);
        $deviceId = (string) Str::uuid();
        $this->useCommunity($this->community);
        Device::create(['id' => $deviceId, 'name' => 'Caja San Isidro', 'receipt_prefix' => 'E01']);

        $this->login('root@x.mx', self::PASSWORD, $deviceId)
            ->assertOk()
            ->assertJsonPath('data.user.panel', Roles::SUPERADMIN);
    }

    public function test_panel_follows_role(): void
    {
        $cobrador = User::factory()->forCommunity($this->community)->create(['email' => 'cobra@x.mx', 'password' => self::PASSWORD]);
        $cobrador->assignRole(Roles::COBRADOR);
        $super = User::factory()->superAdmin()->create(['email' => 'root@x.mx', 'password' => self::PASSWORD]);

        $this->login('cobra@x.mx', self::PASSWORD)->assertJsonPath('data.user.panel', Roles::COBRADOR);
        $this->login('root@x.mx', self::PASSWORD)
            ->assertJsonPath('data.user.panel', Roles::SUPERADMIN)
            ->assertJsonPath('data.user.community', null);

        // Los eventos del superadministrador quedan en la bitácora sin comunidad (solo visibles al sistema).
        $this->assertTrue(app(CurrentCommunity::class)->runAsSystem(
            fn () => AuditLog::whereNull('community_id')->where('user_id', $super->id)->where('action', 'auth.login')->exists()
        ));
    }

    public function test_login_events_stay_in_users_community(): void
    {
        $other = Community::factory()->create();
        $this->login('tesorero@sanisidro.mx', self::PASSWORD)->assertOk();

        $this->useCommunity($other);
        $this->assertSame(0, AuditLog::count());
        $this->useCommunity($this->community);
        $this->assertSame(1, AuditLog::where('action', 'auth.login')->count());
    }
}
