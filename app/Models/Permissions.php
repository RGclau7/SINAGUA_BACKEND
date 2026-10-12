<?php

namespace App\Models;

/**
 * Permisos de spatie/laravel-permission. Se asignan a roles, nunca directamente a
 * personas (SRS 2.1): al rotar cobradores solo se cambia el rol.
 *
 * Matriz de la sección 2.3 del SRS. Los permisos "_asignadas" y "_propio" se completan
 * con las reglas de acceso de App\Auth\AccessRules (vivienda asignada, corte propio).
 */
final class Permissions
{
    public const USUARIOS_GESTIONAR = 'usuarios.gestionar';

    public const PADRON_GESTIONAR = 'padron.gestionar';

    public const PADRON_CONSULTAR_ASIGNADAS = 'padron.consultar_asignadas';

    public const COBROS_REGISTRAR = 'cobros.registrar';

    public const COBROS_REGISTRAR_ASIGNADAS = 'cobros.registrar_asignadas';

    public const DINERO_CONFIRMAR = 'dinero.confirmar_recepcion';

    public const INGRESOS_GASTOS_REGISTRAR = 'ingresos_gastos.registrar';

    public const CORTES_GENERAR = 'cortes.generar';

    public const CORTES_GENERAR_PROPIO = 'cortes.generar_propio';

    public const MOVIMIENTOS_ANULAR = 'movimientos.anular';

    public const BITACORA_CONSULTAR = 'bitacora.consultar';

    public const COMUNIDADES_GESTIONAR = 'comunidades.gestionar';

    /** @var array<string, list<string>> rol => permisos */
    public const MATRIX = [
        Roles::SUPERADMIN => [self::COMUNIDADES_GESTIONAR],
        Roles::ADMIN => [
            self::USUARIOS_GESTIONAR,
            self::PADRON_GESTIONAR,
            self::COBROS_REGISTRAR,
            self::DINERO_CONFIRMAR,
            self::INGRESOS_GASTOS_REGISTRAR,
            self::CORTES_GENERAR,
            self::MOVIMIENTOS_ANULAR,
            self::BITACORA_CONSULTAR,
        ],
        // La gente va con el cobrador a pagar: puede consultar y cobrar a cualquier titular.
        Roles::COBRADOR => [
            self::PADRON_CONSULTAR_ASIGNADAS,
            self::COBROS_REGISTRAR,
            self::CORTES_GENERAR_PROPIO,
        ],
        // Sin acceso al escritorio; sus clientes llegan con móvil y web.
        Roles::HABITANTE => [],
        Roles::PUBLICO => [],
    ];

    /** Roles que un administrador puede asignar dentro de su comunidad. */
    public const ASSIGNABLE_ROLES = [Roles::ADMIN, Roles::COBRADOR, Roles::HABITANTE];
}
