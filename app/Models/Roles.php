<?php

namespace App\Models;

/**
 * Roles de spatie/laravel-permission. M2 define la matriz de permisos.
 */
final class Roles
{
    /** Crea comunidades y su primer administrador; no ve movimientos de ninguna comunidad. */
    public const SUPERADMIN = 'superadministrador';

    public const ADMIN = 'administrador';

    public const COBRADOR = 'cobrador';

    public const HABITANTE = 'habitante';

    public const PUBLICO = 'publico';

    public const ALL = [self::SUPERADMIN, self::ADMIN, self::COBRADOR, self::HABITANTE, self::PUBLICO];

    /** Si un usuario tiene varios roles, entra al panel del primero de esta lista. */
    public const PANEL_PRIORITY = [self::SUPERADMIN, self::ADMIN, self::COBRADOR, self::HABITANTE, self::PUBLICO];
}
