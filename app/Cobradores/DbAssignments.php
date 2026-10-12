<?php

namespace App\Cobradores;

use App\Auth\Assignments;
use App\Models\Asignacion;
use App\Models\Cargo;
use App\Models\Familia;
use App\Models\Habitante;
use App\Models\Pago;
use App\Models\User;

/**
 * Viviendas asignadas a un cobrador según las asignaciones vigentes (M6).
 */
class DbAssignments implements Assignments
{
    public function isAssigned(User $cobrador, string $viviendaId): bool
    {
        return Asignacion::vigentes()->where('cobrador_id', $cobrador->id)->where('vivienda_id', $viviendaId)->exists();
    }

    public function countAssigned(User $cobrador): int
    {
        return Asignacion::vigentes()->where('cobrador_id', $cobrador->id)->count();
    }

    /** @return list<string> */
    public function viviendaIds(User $cobrador): array
    {
        return Asignacion::vigentes()->where('cobrador_id', $cobrador->id)->pluck('vivienda_id')->all();
    }

    /**
     * Ids de una entidad que el cobrador puede descargar: sus viviendas, las familias que viven
     * en ellas, sus integrantes, sus cargos y pagos, y sus propias asignaciones.
     *
     * @return list<string>
     */
    public function visibleIds(User $cobrador, string $entity): array
    {
        $viviendas = fn () => $this->viviendaIds($cobrador);
        $familias = fn () => Familia::whereIn('vivienda_id', $viviendas())->pluck('id')->all();

        return match ($entity) {
            'viviendas' => $viviendas(),
            'familias' => $familias(),
            'habitantes' => Habitante::whereIn('familia_id', $familias())->pluck('id')->all(),
            'cargos' => Cargo::whereIn('familia_id', $familias())->pluck('id')->all(),
            'asignaciones' => Asignacion::where('cobrador_id', $cobrador->id)->pluck('id')->all(),
            'pagos' => Pago::whereIn('familia_id', $familias())->pluck('id')->all(),
            default => [],
        };
    }
}
