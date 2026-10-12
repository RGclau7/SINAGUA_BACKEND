<?php

namespace App\Auth;

use App\Models\User;

/**
 * Viviendas asignadas a un cobrador (implementación: App\Cobradores\DbAssignments).
 */
interface Assignments
{
    public function isAssigned(User $cobrador, string $viviendaId): bool;

    public function countAssigned(User $cobrador): int;
}
