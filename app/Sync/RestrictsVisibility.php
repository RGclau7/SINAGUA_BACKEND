<?php

namespace App\Sync;

use App\Models\User;

/**
 * Handler cuyos cambios no todos los usuarios de la comunidad deben descargar
 * (por ejemplo, la lista de usuarios solo la ve quien los gestiona).
 */
interface RestrictsVisibility
{
    public function visibleTo(User $user): bool;
}
