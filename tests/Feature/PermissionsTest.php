<?php

namespace Tests\Feature;

use App\Auth\AccessRules;
use App\Auth\Assignments;
use App\Models\Community;
use App\Models\Permissions;
use App\Models\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Matriz de permisos del SRS (sección 2.3) y reglas de vivienda asignada / corte propio (RF2).
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cobrador;

    private User $habitante;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($community)->create()->assignRole(Roles::ADMIN);
        $this->cobrador = User::factory()->forCommunity($community)->create()->assignRole(Roles::COBRADOR);
        $this->habitante = User::factory()->forCommunity($community)->create()->assignRole(Roles::HABITANTE);

        // Viviendas asignadas simuladas hasta que exista M6.
        $this->app->instance(Assignments::class, new class implements Assignments
        {
            public function isAssigned(User $cobrador, string $viviendaId): bool
            {
                return $viviendaId === 'vivienda-asignada';
            }

            public function countAssigned(User $cobrador): int
            {
                return 1;
            }
        });
    }

    public function test_matrix_matches_srs(): void
    {
        $this->assertTrue($this->admin->can(Permissions::USUARIOS_GESTIONAR));
        $this->assertTrue($this->admin->can(Permissions::DINERO_CONFIRMAR));
        $this->assertTrue($this->admin->can(Permissions::BITACORA_CONSULTAR));
        $this->assertFalse($this->admin->can(Permissions::COMUNIDADES_GESTIONAR));

        foreach ([Permissions::USUARIOS_GESTIONAR, Permissions::DINERO_CONFIRMAR, Permissions::INGRESOS_GASTOS_REGISTRAR,
            Permissions::MOVIMIENTOS_ANULAR, Permissions::BITACORA_CONSULTAR, Permissions::PADRON_GESTIONAR] as $permission) {
            $this->assertFalse($this->cobrador->can($permission), "El cobrador no debe tener {$permission}");
        }
        // La gente va con el cobrador a pagar: cobra a cualquier titular.
        $this->assertTrue($this->cobrador->can(Permissions::COBROS_REGISTRAR));

        $this->assertSame([], $this->habitante->getAllPermissions()->pluck('name')->all());
    }

    public function test_cobrador_sees_and_charges_every_vivienda(): void
    {
        $asCobrador = Gate::forUser($this->cobrador);
        $this->assertTrue($asCobrador->allows(AccessRules::VER_VIVIENDA, 'vivienda-asignada'));
        $this->assertTrue($asCobrador->allows(AccessRules::COBRAR_VIVIENDA, 'otra-vivienda'));
        $this->assertTrue($asCobrador->allows(AccessRules::VER_VIVIENDA, 'otra-vivienda'));

        $asAdmin = Gate::forUser($this->admin);
        $this->assertTrue($asAdmin->allows(AccessRules::COBRAR_VIVIENDA, 'otra-vivienda'));

        $this->assertFalse(Gate::forUser($this->habitante)->allows(AccessRules::VER_VIVIENDA, 'vivienda-asignada'));
    }

    public function test_cobrador_only_generates_own_corte(): void
    {
        $other = User::factory()->forCommunity($this->cobrador->community)->create()->assignRole(Roles::COBRADOR);

        $this->assertTrue(Gate::forUser($this->cobrador)->allows(AccessRules::GENERAR_CORTE, $this->cobrador->id));
        $this->assertFalse(Gate::forUser($this->cobrador)->allows(AccessRules::GENERAR_CORTE, $other->id));
        $this->assertTrue(Gate::forUser($this->admin)->allows(AccessRules::GENERAR_CORTE, $other->id));
    }

    public function test_role_seeder_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing(
            Permissions::MATRIX[Roles::COBRADOR],
            $this->cobrador->fresh()->getAllPermissions()->pluck('name')->all(),
        );
    }
}
