<?php

namespace App\Auth;

use App\Models\Permissions;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de acceso que dependen del registro, además del permiso del rol (RF2). Hoy los
 * cobradores consultan y cobran a todos los titulares (la gente va con ellos); el permiso
 * "_asignadas" queda para comunidades que repartan viviendas. Cada cobrador genera solo su corte.
 *
 * Uso en los módulos: Gate::forUser($user)->allows(AccessRules::COBRAR_VIVIENDA, $viviendaId)
 * Siempre dentro de la comunidad del usuario (el filtro de comunidad ya aplica a los datos).
 */
final class AccessRules
{
    public const VER_VIVIENDA = 'acceso.ver_vivienda';

    public const COBRAR_VIVIENDA = 'acceso.cobrar_vivienda';

    public const GENERAR_CORTE = 'acceso.generar_corte';

    public static function register(): void
    {
        Gate::define(self::VER_VIVIENDA, fn (User $user, string $viviendaId) => $user->can(Permissions::PADRON_GESTIONAR)
            || $user->can(Permissions::COBROS_REGISTRAR)
            || ($user->can(Permissions::PADRON_CONSULTAR_ASIGNADAS) && app(Assignments::class)->isAssigned($user, $viviendaId)));

        Gate::define(self::COBRAR_VIVIENDA, fn (User $user, string $viviendaId) => $user->can(Permissions::COBROS_REGISTRAR)
            || ($user->can(Permissions::COBROS_REGISTRAR_ASIGNADAS) && app(Assignments::class)->isAssigned($user, $viviendaId)));

        Gate::define(self::GENERAR_CORTE, fn (User $user, string $cobradorId) => $user->can(Permissions::CORTES_GENERAR)
            || ($user->can(Permissions::CORTES_GENERAR_PROPIO) && $cobradorId === $user->id));
    }
}
