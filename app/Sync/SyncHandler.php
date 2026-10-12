<?php

namespace App\Sync;

/**
 * Cada entidad sincronizable (pagos, viviendas, ...) registra un handler.
 * Los módulos M4 en adelante agregan los suyos en SyncServiceProvider.
 */
interface SyncHandler
{
    public function policy(): SyncPolicy;

    /**
     * Aplica la mutación dentro de la transacción y devuelve el estado canónico
     * del registro que se publicará en el feed de cambios (null si se eliminó), o un
     * SyncApplied si además hay avisos para el usuario.
     *
     * Debe autorizar al usuario de la mutación ($mutation->userId) según su rol y,
     * para cobradores, según sus viviendas asignadas (App\Auth\AccessRules).
     *
     * @throws SyncRejected
     */
    public function apply(Mutation $mutation): array|SyncApplied|null;
}
