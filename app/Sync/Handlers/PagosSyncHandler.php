<?php

namespace App\Sync\Handlers;

use App\Cobradores\DbAssignments;
use App\Cobranza\CobranzaService;
use App\Exceptions\ApiException;
use App\Models\Pago;
use App\Models\Permissions;
use App\Models\User;
use App\Movements\VoidsMovements;
use App\Sync\Mutation;
use App\Sync\RestrictsRecords;
use App\Sync\RestrictsVisibility;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRejected;

/**
 * Pagos hechos en el escritorio, quizá sin conexión (M7). Solo se crean o se anulan.
 * create {familia_id, folio, paid_at, lines: [{cargo_id, amount_cents}], advance: [{year, month}],
 * cobradores}: los meses de "advance" se pagan por adelantado (su cargo se crea aquí). El folio es el del
 * equipo (E01-000123) y cada cargo se paga completo; si otro equipo ya cobró alguno de esos
 * cargos, el pago se rechaza (already_paid).
 * El administrador los descarga todos; el cobrador, los de las familias de sus viviendas.
 */
class PagosSyncHandler implements RestrictsRecords, RestrictsVisibility, SyncHandler
{
    use VoidsMovements;

    public function __construct(
        private readonly CobranzaService $cobranza,
        private readonly DbAssignments $assignments,
    ) {}

    public function policy(): SyncPolicy
    {
        return SyncPolicy::AppendOnly;
    }

    public function visibleTo(User $user): bool
    {
        return $user->can(Permissions::COBROS_REGISTRAR) || $user->can(Permissions::COBROS_REGISTRAR_ASIGNADAS);
    }

    public function visibleIdsFor(User $user): ?array
    {
        return $user->can(Permissions::COBROS_REGISTRAR) ? null : $this->assignments->visibleIds($user, Pago::ENTITY);
    }

    public function apply(Mutation $mutation): SyncApplied
    {
        if ($mutation->op === Mutation::OP_VOID) {
            return new SyncApplied(CobranzaService::present(Pago::findOrFail($this->voidFromSync($mutation, Pago::class)['id'])));
        }
        if ($mutation->op !== Mutation::OP_CREATE) {
            throw new SyncRejected('not_supported', 'Un pago no se modifica; si está mal, anúlelo con un motivo.');
        }

        $actor = User::find($mutation->userId);
        if ($actor === null || ! $this->visibleTo($actor)) {
            throw new SyncRejected('forbidden', 'No tiene permiso para cobrar.');
        }

        $lines = collect($mutation->payload['lines'] ?? []);
        $this->cobranza->originDeviceId = $mutation->deviceId;
        try {
            $pago = $this->cobranza->pay(
                $actor,
                (string) ($mutation->payload['familia_id'] ?? ''),
                $lines->pluck('cargo_id')->map(fn ($id) => (string) $id)->all(),
                (string) ($mutation->payload['folio'] ?? ''),
                $mutation->deviceId,
                $mutation->entityId,
                $lines->mapWithKeys(fn ($l) => [(string) ($l['cargo_id'] ?? '') => (int) ($l['amount_cents'] ?? 0)])->all(),
                $mutation->payload['paid_at'] ?? null,
                array_values(array_filter((array) ($mutation->payload['advance'] ?? []), 'is_array')),
                isset($mutation->payload['cobradores']) ? mb_substr((string) $mutation->payload['cobradores'], 0, 500) : null,
            );
        } catch (ApiException $e) {
            throw new SyncRejected($e->errorCode, $e->getMessage());
        } finally {
            $this->cobranza->originDeviceId = null;
        }

        return new SyncApplied(CobranzaService::present($pago->fresh()));
    }
}
