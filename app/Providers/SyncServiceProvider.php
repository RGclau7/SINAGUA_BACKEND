<?php

namespace App\Providers;

use App\Audit\AuditFeed;
use App\Comunidad\AjustesService;
use App\Cuotas\CuotasService;
use App\Models\Asignacion;
use App\Models\Cargo;
use App\Models\CategoriaIngreso;
use App\Models\Ingreso;
use App\Models\Pago;
use App\Padron\PadronService;
use App\Sync\Handlers\AjustesSyncHandler;
use App\Sync\Handlers\AsignacionesSyncHandler;
use App\Sync\Handlers\AuditLogSyncHandler;
use App\Sync\Handlers\CuotasSyncHandler;
use App\Sync\Handlers\IngresosSyncHandler;
use App\Sync\Handlers\PadronSyncHandler;
use App\Sync\Handlers\PagosSyncHandler;
use App\Sync\Handlers\UserSyncHandler;
use App\Sync\SyncRegistry;
use App\Users\UserChangePublisher;
use Illuminate\Support\ServiceProvider;

class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SyncRegistry::class, function () {
            $registry = new SyncRegistry;

            // Cada módulo registra aquí sus entidades.
            $registry->register(UserChangePublisher::ENTITY, $this->app->make(UserSyncHandler::class));
            $registry->register(AuditFeed::ENTITY, new AuditLogSyncHandler);
            $registry->register(Asignacion::ENTITY, $this->app->make(AsignacionesSyncHandler::class));
            $registry->register(Pago::ENTITY, $this->app->make(PagosSyncHandler::class));
            $registry->register(AjustesService::ENTITY, $this->app->make(AjustesSyncHandler::class));
            foreach ([CuotasService::CUOTAS, CuotasService::PERIODOS, Cargo::ENTITY] as $entity) {
                $registry->register($entity, $this->app->make(CuotasSyncHandler::class, ['entity' => $entity]));
            }
            foreach ([CategoriaIngreso::ENTITY, Ingreso::ENTITY] as $entity) {
                $registry->register($entity, $this->app->make(IngresosSyncHandler::class, ['entity' => $entity]));
            }
            foreach (array_keys(PadronService::ENTITIES) as $entity) {
                $registry->register($entity, $this->app->make(PadronSyncHandler::class, ['entity' => $entity]));
            }

            return $registry;
        });
    }
}
