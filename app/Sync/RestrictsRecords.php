<?php

namespace App\Sync;

use App\Models\User;

/**
 * Handler cuyos registros un usuario puede descargar solo en parte (el cobrador recibe
 * únicamente sus viviendas asignadas y lo que cuelga de ellas).
 */
interface RestrictsRecords
{
    /**
     * Ids que el usuario puede descargar, o null si puede ver todos.
     *
     * @return list<string>|null
     */
    public function visibleIdsFor(User $user): ?array;
}
