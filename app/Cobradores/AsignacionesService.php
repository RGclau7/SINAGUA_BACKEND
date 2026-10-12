<?php

namespace App\Cobradores;

use App\Exceptions\ApiException;
use App\Models\Asignacion;
use App\Models\AuditLog;
use App\Models\Roles;
use App\Models\SyncChange;
use App\Models\User;
use App\Models\Vivienda;
use App\Tenancy\CurrentCommunity;
use Illuminate\Support\Facades\DB;

/**
 * Asignación de viviendas a cobradores (M6). La usan la API REST y la sincronización.
 * Una vivienda tiene a lo más un cobrador vigente: asignarla a otro cierra la asignación anterior.
 */
class AsignacionesService
{
    /** Equipo cuya mutación provocó el cambio (lo fija la sincronización). */
    public ?string $originDeviceId = null;

    public function __construct(private readonly CurrentCommunity $context) {}

    public function find(string $id): Asignacion
    {
        $this->communityId();

        return Asignacion::find($id) ?? throw new ApiException('not_found', 'Asignación no encontrada.', 404);
    }

    public function cobrador(string $id): User
    {
        $user = User::where('community_id', $this->communityId())->find($id);
        if ($user === null || ! $user->hasRole(Roles::COBRADOR)) {
            throw new ApiException('invalid_cobrador', 'Elija un cobrador de la comunidad.', 422);
        }
        if (! $user->is_active) {
            throw new ApiException('invalid_cobrador', "{$user->name} está desactivado; actívelo antes de asignarle viviendas.", 422);
        }

        return $user;
    }

    /**
     * Asigna la vivienda al cobrador desde hoy. Si tenía otro cobrador, esa asignación se cierra.
     * Publica en el feed la asignación cerrada; la nueva la publica quien llama.
     */
    public function assign(User $actor, User $cobrador, string $viviendaId, ?string $id = null): Asignacion
    {
        $vivienda = Vivienda::find($viviendaId) ?? throw new ApiException('not_found', 'Vivienda no encontrada.', 404);
        if (! $vivienda->is_active) {
            throw new ApiException('parent_inactive', "La vivienda {$vivienda->label()} está dada de baja.", 422);
        }
        if ($id !== null && $this->context->runAsSystem(fn () => Asignacion::whereKey($id)->exists())) {
            throw new ApiException('id_taken', 'El identificador del registro ya existe.', 409);
        }

        return DB::transaction(function () use ($actor, $cobrador, $vivienda, $id) {
            $current = Asignacion::vigentes()->where('vivienda_id', $vivienda->id)->lockForUpdate()->first();
            if ($current?->cobrador_id === $cobrador->id) {
                throw new ApiException('already_assigned', "La vivienda {$vivienda->label()} ya es de {$cobrador->name}.", 409);
            }

            $previous = null;
            if ($current !== null) {
                $previous = $current->cobrador?->name;
                $this->close($current);
            }

            $asignacion = new Asignacion;
            if ($id !== null) {
                $asignacion->id = $id;
            }
            $asignacion->forceFill([
                'cobrador_id' => $cobrador->id,
                'vivienda_id' => $vivienda->id,
                'starts_on' => now()->toDateString(),
            ])->save();

            AuditLog::record('asignacion.created', [
                'user_id' => $actor->id,
                'entity' => Asignacion::ENTITY,
                'entity_id' => $asignacion->id,
                'data' => array_filter([
                    'cobrador' => $cobrador->name,
                    'address' => $vivienda->label(),
                    'previous_cobrador' => $previous,
                ]),
            ]);

            return $asignacion;
        });
    }

    /** Quita la vivienda al cobrador (cierra la asignación; no se borra). */
    public function end(User $actor, Asignacion $asignacion): Asignacion
    {
        if ($asignacion->ends_on !== null) {
            throw new ApiException('already_ended', 'Esta asignación ya terminó.', 409);
        }

        $asignacion->forceFill(['ends_on' => now()->toDateString()])->save();
        AuditLog::record('asignacion.ended', [
            'user_id' => $actor->id,
            'entity' => Asignacion::ENTITY,
            'entity_id' => $asignacion->id,
            'data' => [
                'cobrador' => $asignacion->cobrador?->name,
                'address' => $asignacion->vivienda?->label(),
            ],
        ]);

        return $asignacion;
    }

    /**
     * Pasa todas las viviendas vigentes de un cobrador a otro (por ejemplo, al darlo de baja).
     *
     * @return list<Asignacion> las asignaciones nuevas
     */
    public function reassignAll(User $actor, User $from, User $to): array
    {
        if ($from->is($to)) {
            throw new ApiException('validation_error', 'Elija un cobrador distinto.', 422);
        }

        return DB::transaction(fn () => Asignacion::vigentes()->where('cobrador_id', $from->id)->get()
            ->map(fn (Asignacion $a) => $this->assign($actor, $to, $a->vivienda_id))
            ->all());
    }

    public function publish(Asignacion $asignacion, string $op): void
    {
        (new SyncChange)->forceFill([
            'community_id' => $asignacion->community_id,
            'entity' => Asignacion::ENTITY,
            'entity_id' => $asignacion->id,
            'op' => $op,
            'payload' => self::present($asignacion->fresh()),
            'origin_device_id' => $this->originDeviceId,
            'created_at' => now(),
        ])->save();
    }

    public static function present(Asignacion $a): array
    {
        return [
            'id' => $a->id,
            'cobrador_id' => $a->cobrador_id,
            'vivienda_id' => $a->vivienda_id,
            'starts_on' => $a->starts_on?->format('Y-m-d'),
            'ends_on' => $a->ends_on?->format('Y-m-d'),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    private function close(Asignacion $asignacion): void
    {
        $asignacion->forceFill(['ends_on' => now()->toDateString()])->save();
        $this->publish($asignacion, 'update');
    }

    private function communityId(): string
    {
        return $this->context->id() ?? throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
    }
}
