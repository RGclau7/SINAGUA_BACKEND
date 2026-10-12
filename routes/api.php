<?php

use App\Http\Controllers\Api\V1\Admin\CommunityController;
use App\Http\Controllers\Api\V1\AjustesController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CobradoresController;
use App\Http\Controllers\Api\V1\CobranzaController;
use App\Http\Controllers\Api\V1\CuotasController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IngresosController;
use App\Http\Controllers\Api\V1\MovementController;
use App\Http\Controllers\Api\V1\PadronController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\UserController;
use App\Models\Permissions;
use Illuminate\Support\Facades\Route;

// Todas las rutas de este archivo cuelgan de /api/v1 (ver bootstrap/app.php).

Route::get('/health', HealthController::class)->name('health');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // Datos de una comunidad: la comunidad sale siempre del usuario autenticado.
    Route::middleware('community')->group(function () {
        Route::post('/devices', [DeviceController::class, 'store'])->name('devices.store');

        Route::post('/sync/push', [SyncController::class, 'push'])->name('sync.push');
        Route::get('/sync/pull', [SyncController::class, 'pull'])->name('sync.pull');

        // Bitácora (M3): solo lectura, para quien tiene bitacora.consultar.
        Route::middleware('perm:'.Permissions::BITACORA_CONSULTAR)->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
            Route::get('/audit-logs/verify', [AuditLogController::class, 'verify'])->name('audit-logs.verify');
        });

        // Anulación de movimientos (M3): solo quien tiene movimientos.anular.
        Route::post('/movements/{entity}/{id}/void', [MovementController::class, 'void'])
            ->middleware('perm:'.Permissions::MOVIMIENTOS_ANULAR)->whereUuid('id')->name('movements.void');

        // Padrón (M4): viviendas, familias y habitantes; solo quien tiene padron.gestionar.
        Route::middleware('perm:'.Permissions::PADRON_GESTIONAR)->prefix('padron')->name('padron.')->group(function () {
            $entity = 'viviendas|familias|habitantes';
            Route::post('/registro', [PadronController::class, 'register'])->name('register');
            Route::get('/{entity}', [PadronController::class, 'index'])->where('entity', $entity)->name('index');
            Route::post('/{entity}', [PadronController::class, 'store'])->where('entity', $entity)->name('store');
            Route::get('/{entity}/{id}', [PadronController::class, 'show'])->where('entity', $entity)->whereUuid('id')->name('show');
            Route::patch('/{entity}/{id}', [PadronController::class, 'update'])->where('entity', $entity)->whereUuid('id')->name('update');
            Route::post('/{entity}/{id}/deactivate', [PadronController::class, 'deactivate'])->where('entity', $entity)->whereUuid('id')->name('deactivate');
            Route::post('/{entity}/{id}/activate', [PadronController::class, 'activate'])->where('entity', $entity)->whereUuid('id')->name('activate');
        });

        // Ajustes de cobro: los ve toda la comunidad; los cambia el administrador.
        Route::get('/ajustes', [AjustesController::class, 'show'])->name('ajustes.show');
        Route::patch('/ajustes', [AjustesController::class, 'update'])->middleware('perm:'.Permissions::PADRON_GESTIONAR)->name('ajustes.update');

        // Cuotas y cargos (M5): quien gestiona padrón y cuotas (administrador).
        Route::middleware('perm:'.Permissions::PADRON_GESTIONAR)->group(function () {
            Route::get('/cuotas', [CuotasController::class, 'index'])->name('cuotas.index');
            Route::post('/cuotas', [CuotasController::class, 'store'])->name('cuotas.store');
            Route::patch('/cuotas/{id}', [CuotasController::class, 'update'])->whereUuid('id')->name('cuotas.update');
            Route::get('/periodos', [CuotasController::class, 'periodos'])->name('periodos.index');
            Route::post('/periodos/generar', [CuotasController::class, 'generate'])->name('periodos.generate');
            Route::get('/periodos/{id}/cargos', [CuotasController::class, 'cargos'])->whereUuid('id')->name('periodos.cargos');
            Route::get('/familias/{id}/cargos', [CuotasController::class, 'familia'])->whereUuid('id')->name('familias.cargos');
            Route::get('/multas', [CuotasController::class, 'multas'])->name('multas.index');
            Route::post('/multas', [CuotasController::class, 'storeMulta'])->name('multas.store');
        });

        // Cobradores y asignación de viviendas (M6): quien gestiona usuarios y cobradores.
        Route::middleware('perm:'.Permissions::USUARIOS_GESTIONAR)->group(function () {
            Route::get('/cobradores', [CobradoresController::class, 'index'])->name('cobradores.index');
            Route::get('/asignaciones', [CobradoresController::class, 'asignaciones'])->name('asignaciones.index');
            Route::post('/asignaciones', [CobradoresController::class, 'store'])->name('asignaciones.store');
            Route::post('/asignaciones/reasignar', [CobradoresController::class, 'reassign'])->name('asignaciones.reassign');
            Route::post('/asignaciones/{id}/end', [CobradoresController::class, 'end'])->whereUuid('id')->name('asignaciones.end');
        });
        // El cobrador consulta sus viviendas asignadas.
        Route::get('/mis-viviendas', [CobradoresController::class, 'mine'])
            ->middleware('perm:'.Permissions::PADRON_CONSULTAR_ASIGNADAS)->name('mis-viviendas');

        // Cobranza (M7): administrador (todas las viviendas) y cobrador (las asignadas).
        Route::middleware('perm:'.Permissions::COBROS_REGISTRAR.','.Permissions::COBROS_REGISTRAR_ASIGNADAS)->group(function () {
            Route::get('/cobranza/buscar', [CobranzaController::class, 'buscar'])->name('cobranza.buscar');
            Route::get('/cobranza/familias/{id}', [CobranzaController::class, 'estado'])->whereUuid('id')->name('cobranza.estado');
            Route::get('/pagos', [CobranzaController::class, 'index'])->name('pagos.index');
            Route::post('/pagos', [CobranzaController::class, 'store'])->name('pagos.store');
        });

        // Ingresos extras (M8): categorías (fondos) y lo que entra a cada una.
        Route::middleware('perm:'.Permissions::INGRESOS_GASTOS_REGISTRAR)->group(function () {
            Route::get('/ingresos/categorias', [IngresosController::class, 'categorias'])->name('ingresos.categorias.index');
            Route::post('/ingresos/categorias', [IngresosController::class, 'storeCategoria'])->name('ingresos.categorias.store');
            Route::patch('/ingresos/categorias/{id}', [IngresosController::class, 'updateCategoria'])->whereUuid('id')->name('ingresos.categorias.update');
            Route::get('/ingresos', [IngresosController::class, 'index'])->name('ingresos.index');
            Route::post('/ingresos', [IngresosController::class, 'store'])->name('ingresos.store');
        });

        // Usuarios y permisos (M2): solo quien tiene usuarios.gestionar (administrador).
        Route::middleware('perm:'.Permissions::USUARIOS_GESTIONAR)->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{id}', [UserController::class, 'show'])->whereUuid('id')->name('users.show');
            Route::patch('/users/{id}', [UserController::class, 'update'])->whereUuid('id')->name('users.update');
            Route::post('/users/{id}/activate', [UserController::class, 'activate'])->whereUuid('id')->name('users.activate');
            Route::post('/users/{id}/deactivate', [UserController::class, 'deactivate'])->whereUuid('id')->name('users.deactivate');
        });
    });

    // Superadministrador: gestiona comunidades, sin acceso a sus movimientos.
    Route::middleware('superadmin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/communities', [CommunityController::class, 'index'])->name('communities.index');
        Route::post('/communities', [CommunityController::class, 'store'])->name('communities.store');
    });
});
