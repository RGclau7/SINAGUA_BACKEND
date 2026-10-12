<?php

namespace App\Providers;

use App\Auth\AccessRules;
use App\Auth\Assignments;
use App\Cobradores\DbAssignments;
use App\Cobranza\CobranzaService;
use App\Cuotas\CargoDebts;
use App\Cuotas\CuotasPublisher;
use App\Ingresos\IngresosService;
use App\Models\Cargo;
use App\Models\Ingreso;
use App\Models\Pago;
use App\Movements\MovementRegistry;
use App\Movements\MovementVoided;
use App\Padron\Debts;
use App\Padron\PadronPublisher;
use App\Tenancy\CurrentCommunity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCommunity::class);
        // Viviendas asignadas a cada cobrador (M6).
        $this->app->bind(Assignments::class, DbAssignments::class);
        $this->app->singleton(MovementRegistry::class);
        // Adeudo = cargos sin anular; M7 restará los pagos.
        $this->app->bind(Debts::class, CargoDebts::class);
        $this->app->singleton(CuotasPublisher::class);
        $this->app->singleton(CobranzaService::class);
        $this->app->singleton(PadronPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El token expira tras N minutos sin usarse (además de su vida máxima, expires_at).
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            $lastActivity = $token->last_used_at ?? $token->created_at;

            return $isValid && $lastActivity->gt(now()->subMinutes(config('sinagua.auth.idle_minutes')));
        });

        AccessRules::register();

        // Cargos: se anulan con /movements/cargos/{id}/void y el cambio baja a los equipos.
        $this->app->afterResolving(MovementRegistry::class, function (MovementRegistry $r) {
            $r->register(Cargo::ENTITY, Cargo::class);
            $r->register(Pago::ENTITY, Pago::class);
            $r->register(Ingreso::ENTITY, Ingreso::class);
        });
        Event::listen(MovementVoided::class, function (MovementVoided $event) {
            match (true) {
                $event->movement instanceof Cargo => app(CuotasPublisher::class)->publish($event->movement, 'void'),
                // Al anular un pago sus cargos vuelven a quedar por pagar (se calcula al consultar).
                $event->movement instanceof Pago => app(CobranzaService::class)->publish($event->movement, 'void'),
                // Al anular un ingreso baja el saldo de su categoría.
                $event->movement instanceof Ingreso => app(IngresosService::class)->publish($event->movement, 'void'),
                default => null,
            };
        });

        // Columnas de anulación para las tablas de movimientos: $table->voidable();
        Blueprint::macro('voidable', function () {
            /** @var Blueprint $this */
            $this->timestamp('voided_at')->nullable();
            $this->uuid('voided_by')->nullable();
            $this->text('void_reason')->nullable();
        });

        // Freno general por IP para el login (el bloqueo por cuenta está en LoginService).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
