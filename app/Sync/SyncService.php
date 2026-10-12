<?php

namespace App\Sync;

use App\Models\AuditLog;
use App\Models\SyncChange;
use App\Models\SyncMutation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SyncService
{
    /** UUID que no corresponde a ningún registro (para "ninguno visible"). */
    private const NO_ID = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly SyncRegistry $registry) {}

    /**
     * Procesa la cola de salida de un equipo. Cada mutación va en su propia
     * transacción para que una rechazada no tumbe a las demás.
     *
     * @param  list<Mutation>  $mutations
     * @return list<array{mutation_id: string, status: string, code?: string, message?: string}>
     */
    public function push(array $mutations): array
    {
        return array_map(fn (Mutation $m) => $this->pushOne($m), $mutations);
    }

    /**
     * Cambios de la comunidad posteriores al cursor que el usuario puede ver.
     *
     * "scope" resume qué entidades se le ocultan; si cambia (otro usuario con más permisos
     * en el mismo equipo), el cliente debe volver a descargar desde el cursor 0.
     *
     * @return array{changes: list<array<string, mixed>>, next_cursor: int, has_more: bool, scope: string}
     */
    public function pull(int $cursor, int $limit, ?User $user = null): array
    {
        $hidden = $user === null ? [] : $this->hiddenEntitiesFor($user);
        $restricted = $user === null ? [] : $this->restrictedIdsFor($user, $hidden);

        $rows = SyncChange::query()
            ->where('id', '>', $cursor)
            ->when($hidden !== [], fn ($q) => $q->whereNotIn('entity', $hidden))
            ->when($restricted !== [], function ($q) use ($restricted) {
                $q->where(function ($q) use ($restricted) {
                    $q->whereNotIn('entity', array_keys($restricted));
                    foreach ($restricted as $entity => $ids) {
                        $q->orWhere(fn ($q) => $q->where('entity', $entity)->whereIn('entity_id', $ids === [] ? [self::NO_ID] : $ids));
                    }
                });
            })
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'changes' => $rows->map(fn (SyncChange $c) => [
                'cursor' => $c->id,
                'entity' => $c->entity,
                'entity_id' => $c->entity_id,
                'op' => $c->op,
                'payload' => $c->payload,
                'changed_at' => $c->created_at->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $rows->last()?->id ?? $cursor,
            'has_more' => $hasMore,
            'scope' => $this->scope($hidden, $restricted),
        ];
    }

    /**
     * "all", "hidden:a,b" y, si solo ve parte de algunas entidades, "|restricted:x,y:<huella>".
     * La huella cambia cuando cambia lo que puede ver (otra vivienda asignada), y entonces el
     * equipo borra su copia y vuelve a descargar desde el cursor 0.
     */
    private function scope(array $hidden, array $restricted): string
    {
        $scope = $hidden === [] ? 'all' : 'hidden:'.implode(',', $hidden);
        if ($restricted === []) {
            return $scope;
        }

        ksort($restricted);
        $fingerprint = substr(hash('sha256', json_encode(array_map(function ($ids) {
            sort($ids);

            return $ids;
        }, $restricted))), 0, 12);

        return $scope.'|restricted:'.implode(',', array_keys($restricted)).':'.$fingerprint;
    }

    /** @return array<string, list<string>> */
    private function restrictedIdsFor(User $user, array $hidden): array
    {
        $restricted = [];
        foreach ($this->registry->entities() as $entity) {
            $handler = $this->registry->handlerFor($entity);
            if (! in_array($entity, $hidden, true) && $handler instanceof RestrictsRecords
                && ($ids = $handler->visibleIdsFor($user)) !== null) {
                $restricted[$entity] = array_values($ids);
            }
        }

        return $restricted;
    }

    /** @return list<string> */
    private function hiddenEntitiesFor(User $user): array
    {
        $hidden = [];
        foreach ($this->registry->entities() as $entity) {
            $handler = $this->registry->handlerFor($entity);
            if ($handler instanceof RestrictsVisibility && ! $handler->visibleTo($user)) {
                $hidden[] = $entity;
            }
        }
        sort($hidden);

        return $hidden;
    }

    private function pushOne(Mutation $m): array
    {
        $previous = SyncMutation::find($m->mutationId);
        if ($previous !== null) {
            return $this->result($previous);
        }

        $notices = [];

        try {
            $record = DB::transaction(function () use ($m, &$notices) {
                $handler = $this->registry->handlerFor($m->entity);
                if ($handler === null) {
                    throw new SyncRejected('entity_not_supported', 'Este tipo de registro todavía no se puede guardar en el servidor.');
                }
                if ($handler->policy() === SyncPolicy::AppendOnly && ! in_array($m->op, [Mutation::OP_CREATE, Mutation::OP_VOID], true)) {
                    throw new SyncRejected('append_only', 'Este movimiento no se puede modificar; si está mal, anúlalo con un motivo.');
                }

                $applied = $handler->apply($m);
                $applied = $applied instanceof SyncApplied ? $applied : new SyncApplied($applied);
                $notices = $applied->notices;

                // community_id lo asigna BelongsToCommunity desde el contexto de la petición.
                SyncChange::create([
                    'entity' => $m->entity,
                    'entity_id' => $m->entityId,
                    'op' => $m->op,
                    'payload' => $applied->canonical,
                    'origin_device_id' => $m->deviceId,
                    'mutation_id' => $m->mutationId,
                    'created_at' => now(),
                ]);
                $this->audit('sync.accepted', $m);

                return $this->store($m, SyncMutation::STATUS_ACCEPTED);
            });
        } catch (SyncRejected $e) {
            // La transacción ya revirtió lo que el handler hubiera escrito.
            $record = DB::transaction(function () use ($m, $e) {
                $this->audit('sync.rejected', $m, ['code' => $e->reasonCode, 'message' => $e->getMessage()]);

                return $this->store($m, SyncMutation::STATUS_REJECTED, $e->reasonCode, $e->getMessage());
            });
        } catch (UniqueConstraintViolationException) {
            // Otra petición procesó la misma mutación al mismo tiempo. Si no está en esta
            // comunidad, el id ya lo usó otra: se rechaza sin revelar nada de ella.
            $record = SyncMutation::find($m->mutationId);
            if ($record === null) {
                return [
                    'mutation_id' => $m->mutationId,
                    'status' => SyncMutation::STATUS_REJECTED,
                    'code' => 'duplicate_mutation_id',
                    'message' => 'Este cambio ya se había enviado antes.',
                ];
            }
        }

        $result = $this->result($record);
        if ($notices !== []) {
            $result['notices'] = $notices;
        }

        return $result;
    }

    private function store(Mutation $m, string $status, ?string $code = null, ?string $message = null): SyncMutation
    {
        return SyncMutation::create([
            'mutation_id' => $m->mutationId,
            'device_id' => $m->deviceId,
            'user_id' => $m->userId,
            'entity' => $m->entity,
            'entity_id' => $m->entityId,
            'op' => $m->op,
            'status' => $status,
            'reason_code' => $code,
            'reason_message' => $message,
            'occurred_at' => $m->occurredAt,
            'received_at' => now(),
        ]);
    }

    private function audit(string $action, Mutation $m, array $extra = []): void
    {
        AuditLog::record($action, [
            'user_id' => $m->userId,
            'device_id' => $m->deviceId,
            'entity' => $m->entity,
            'entity_id' => $m->entityId,
            'data' => ['mutation_id' => $m->mutationId, 'op' => $m->op, 'payload' => $m->payload, ...$extra],
            'created_at' => now(),
        ]);
    }

    private function result(SyncMutation $record): array
    {
        return array_filter([
            'mutation_id' => $record->mutation_id,
            'status' => $record->status,
            'code' => $record->reason_code,
            'message' => $record->reason_message,
        ], fn ($v) => $v !== null);
    }
}
