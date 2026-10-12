<?php

namespace App\Sync\Handlers;

use App\Exceptions\ApiException;
use App\Ingresos\IngresosService;
use App\Models\CategoriaIngreso;
use App\Models\Ingreso;
use App\Models\Permissions;
use App\Models\User;
use App\Movements\VoidsMovements;
use App\Sync\Mutation;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;
use Illuminate\Validation\ValidationException;

/**
 * Ingresos extras hechos en el escritorio, quizá sin conexión (M8).
 *
 * - categorias_ingreso (gana el servidor): create/update {name, description, is_active}.
 * - ingresos (solo se anexan): create {categoria_id, amount_cents, received_on, note}; void {void_reason}.
 *
 * Solo los descarga quien registra ingresos y gastos (administrador).
 */
class IngresosSyncHandler implements RestrictsVisibility, SyncHandler
{
    use VoidsMovements;

    public function __construct(
        private readonly string $entity,
        private readonly IngresosService $service,
    ) {}

    public function policy(): SyncPolicy
    {
        return $this->entity === Ingreso::ENTITY ? SyncPolicy::AppendOnly : SyncPolicy::ServerWins;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::INGRESOS_GASTOS_REGISTRAR);
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        if ($this->entity === Ingreso::ENTITY && $mutation->op === Mutation::OP_VOID) {
            return new SyncApplied(IngresosService::present(Ingreso::findOrFail($this->voidFromSync($mutation, Ingreso::class)['id'])));
        }

        $actor = User::find($mutation->userId);
        if ($actor === null || ! $this->visibleTo($actor)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para registrar ingresos.');
        }

        try {
            $record = $this->entity === Ingreso::ENTITY ? $this->ingreso($actor, $mutation) : $this->categoria($actor, $mutation);
        } catch (ValidationException $e) {
            throw new SyncRejected('validation_error', collect($e->errors())->flatten()->implode(' '));
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        }

        return new SyncApplied(IngresosService::present($record->fresh()));
    }

    private function ingreso(User $actor, Mutation $m): Ingreso
    {
        if ($m->op !== Mutation::OP_CREATE) {
            throw new SyncRejected('not_supported', 'Un ingreso no se modifica; si está mal, anúlelo con un motivo.');
        }

        return $this->service->createIngreso($actor,
            array_intersect_key($m->payload, array_flip(['categoria_id', 'amount_cents', 'received_on', 'note'])), $m->entityId, $m->deviceId);
    }

    private function categoria(User $actor, Mutation $m): CategoriaIngreso
    {
        $fields = array_intersect_key($m->payload, array_flip(['name', 'description', 'is_active']));

        return match ($m->op) {
            Mutation::OP_CREATE => $this->service->createCategoria($actor, $fields, $m->entityId),
            Mutation::OP_UPDATE => $this->service->updateCategoria($actor, $this->service->findCategoria($m->entityId), $fields),
            default => throw new SyncRejected('not_supported', 'Las categorías no se borran; desactívelas.'),
        };
    }
}
