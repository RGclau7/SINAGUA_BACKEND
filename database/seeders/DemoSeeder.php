<?php

namespace Database\Seeders;

use App\Cobradores\AsignacionesService;
use App\Comunidad\AjustesService;
use App\Cuotas\CuotasPublisher;
use App\Cuotas\CuotasService;
use App\Ingresos\IngresosService;
use App\Models\Asignacion;
use App\Models\CategoriaIngreso;
use App\Models\Community;
use App\Models\Cuota;
use App\Models\Familia;
use App\Models\Roles;
use App\Models\User;
use App\Models\Vivienda;
use App\Padron\PadronPublisher;
use App\Padron\PadronService;
use App\Support\PersonData;
use App\Tenancy\CurrentCommunity;
use App\Users\UserChangePublisher;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos de prueba para revisar la app (ver PRUEBAS.md). Solo en entorno local:
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    /** Contraseña de prueba de todas las cuentas de demostración. */
    public const PASSWORD = 'prueba12345';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoSeeder solo se usa en entorno local.');
        }

        $this->call(RoleSeeder::class);

        $sanIsidro = app(CurrentCommunity::class)->runAsSystem(function () {
            $this->user('superadmin@sinagua.test', ['Comité', 'Superadministrador', null, '4151000000', 'Oficina del comité'], Roles::SUPERADMIN, null);

            $sanIsidro = Community::updateOrCreate(['name' => 'San Isidro (prueba)'], [
                'state' => 'Guanajuato', 'municipality' => 'San Miguel de Allende',
                'locality' => 'San Isidro, camino al pozo s/n', 'contact_phone' => '4151234567',
            ]);
            // Ajustes de cobro de ejemplo: multa de $50 por mes no pagado antes del día 10 del mes siguiente.
            if ((int) $sanIsidro->multa_cents === 0) {
                $sanIsidro->forceFill(['multa_cents' => 5000, 'multa_dia_limite' => 10, 'meses_adelanto' => 3])->save();
            }
            $this->user('admin@sanisidro.test', ['Ana María', 'López', 'Hernández', '4151112233', 'Calle del Pozo 12, San Isidro'], Roles::ADMIN, $sanIsidro);
            $this->user('cobrador@sanisidro.test', ['José', 'Martínez', 'Ruiz', '4152223344', 'Callejón Real 4, San Isidro'], Roles::COBRADOR, $sanIsidro);

            $canada = Community::updateOrCreate(['name' => 'La Cañada (prueba)'], [
                'state' => 'Guanajuato', 'municipality' => 'San Miguel de Allende',
                'locality' => 'La Cañada de la Virgen, km 3', 'contact_phone' => '4157654321',
            ]);
            $this->user('admin@lacanada.test', ['Pedro', 'García', null, '4153334455', 'Av. Principal 8, La Cañada'], Roles::ADMIN, $canada);

            return $sanIsidro;
        });

        $this->padron($sanIsidro);
    }

    /**
     * Padrón, cuota y asignaciones de ejemplo en San Isidro (solo lo que falte). Se registran como
     * la administradora para que queden en la bitácora.
     */
    private function padron(Community $community): void
    {
        $context = app(CurrentCommunity::class);
        $context->set($community->id);

        try {
            // Cuota de ejemplo: $150.00 al mes desde enero del año en curso.
            if (! Cuota::exists()) {
                $admin = User::where('email', 'admin@sanisidro.test')->firstOrFail();
                $cuota = app(CuotasService::class)->createCuota($admin, ['amount_cents' => 15000, 'valid_from' => now()->format('Y').'-01', 'note' => 'Cuota de ejemplo']);
                app(CuotasPublisher::class)->publish($cuota, 'create');
            }

            if (! Familia::exists()) {
                $this->familias();
            }

            // El cobrador de prueba cobra las 3 primeras viviendas (por domicilio).
            if (! Asignacion::exists()) {
                $admin = User::where('email', 'admin@sanisidro.test')->firstOrFail();
                $service = app(AsignacionesService::class);
                $cobrador = $service->cobrador(User::where('email', 'cobrador@sanisidro.test')->value('id'));
                foreach (Vivienda::where('is_active', true)->orderBy('address')->limit(3)->pluck('id') as $viviendaId) {
                    $service->publish($service->assign($admin, $cobrador, $viviendaId), 'create');
                }
            }
            // Ingresos extras de ejemplo: los baños comunitarios, con dos domingos cobrados.
            if (! CategoriaIngreso::exists()) {
                $admin = User::where('email', 'admin@sanisidro.test')->firstOrFail();
                $ingresos = app(IngresosService::class);
                $banos = $ingresos->createCategoria($admin, ['name' => 'Baños comunitarios', 'description' => 'Lo que se cobra por usar los baños']);
                $ingresos->publish($banos, 'create');
                foreach ([[14, 18500, 'Domingo de tianguis'], [7, 12000, null]] as [$days, $cents, $note]) {
                    $ingreso = $ingresos->createIngreso($admin, [
                        'categoria_id' => $banos->id, 'amount_cents' => $cents,
                        'received_on' => now()->subDays($days)->toDateString(), 'note' => $note,
                    ]);
                    $ingresos->publish($ingreso, 'create');
                }
            }
            app(AjustesService::class)->publish($community->fresh());
        } finally {
            $context->set(null);
        }
    }

    /** Las 5 familias de ejemplo, dos de ellas en el mismo domicilio. */
    private function familias(): void
    {
        $admin = User::where('email', 'admin@sanisidro.test')->firstOrFail();
        $padron = app(PadronService::class);
        $publisher = app(PadronPublisher::class);
        $familias = [
            [['Calle del Pozo 3', 'Centro', 'Frente a la capilla'], ['Juana', 'Pérez', 'Gómez', '4155551201'], false],
            [['Calle del Pozo 3', 'Centro', null], ['Luis', 'Ramírez', 'Pérez', null], true],
            [['Callejón Real 9', 'Barrio de Abajo', null], ['Margarita', 'Hernández', 'Soto', '4155551202'], false],
            [['Camino al Salto 21', 'La Loma', 'Casa azul'], ['Ramón', 'Torres', null, '4155551203'], false],
            [['Calle Hidalgo 10', 'Centro', null], ['Teresa', 'Morales', 'Cruz', null], false],
        ];

        foreach ($familias as [[$address, $neighborhood, $reference], [$given, $paternal, $maternal, $phone], $shared]) {
            $result = $padron->register($admin, [
                'vivienda' => ['address' => $address, 'neighborhood' => $neighborhood, 'reference' => $reference],
                'titular' => ['given_names' => $given, 'paternal_surname' => $paternal, 'maternal_surname' => $maternal, 'phone' => $phone],
                'confirm_shared_address' => $shared,
            ]);
            if ($result['vivienda_created']) {
                $publisher->publish($result['vivienda'], 'create');
            }
            $publisher->publish($result['familia'], 'create');
            $publisher->publish($result['titular'], 'create');
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: ?string, 3: string, 4: string}  $person  nombre(s), apellidos, teléfono, dirección
     */
    private function user(string $email, array $person, string $role, ?Community $community): void
    {
        [$given, $paternal, $maternal, $phone, $address] = $person;
        $user = User::firstOrNew(['email' => $email]);
        // Volver a correr el seeder reactiva las cuentas y quita bloqueos de intentos fallidos.
        $user->fill([
            'name' => PersonData::fullName($given, $paternal, $maternal),
            'given_names' => $given, 'paternal_surname' => $paternal, 'maternal_surname' => $maternal,
            'phone' => $phone, 'address' => $address,
            'password' => self::PASSWORD, 'is_active' => true,
        ]);
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null]);
        $user->community_id = $community?->id;
        $user->save();
        $user->syncRoles([$role]);

        // Para que los equipos de la comunidad lo vean en la pantalla de usuarios.
        if ($community !== null) {
            app(UserChangePublisher::class)->publish($user, 'create');
        }
    }
}
