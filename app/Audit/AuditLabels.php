<?php

namespace App\Audit;

/**
 * Textos en español de cada acción de la bitácora y su tipo para filtrar (CDU24).
 */
final class AuditLabels
{
    /** acción => [texto, tipo] */
    private const ACTIONS = [
        'auth.login' => ['Inició sesión', 'acceso'],
        'auth.logout' => ['Cerró sesión', 'acceso'],
        'auth.failed' => ['Intento de acceso fallido', 'acceso'],
        'auth.locked' => ['Cuenta bloqueada por intentos fallidos', 'acceso'],
        'auth.rejected_inactive' => ['Intento de acceso de cuenta desactivada', 'acceso'],
        'user.created' => ['Registró un usuario', 'usuarios'],
        'user.updated' => ['Modificó un usuario', 'usuarios'],
        'user.activated' => ['Activó un usuario', 'usuarios'],
        'user.deactivated' => ['Desactivó un usuario', 'usuarios'],
        'device.registered' => ['Registró un equipo', 'equipos'],
        'community.created' => ['Registró la comunidad', 'comunidad'],
        'sync.accepted' => ['Sincronizó un cambio', 'sincronización'],
        'sync.rejected' => ['El servidor rechazó un cambio', 'sincronización'],
        'movement.voided' => ['Anuló un movimiento', 'anulaciones'],
        'vivienda.created' => ['Registró una vivienda', 'padrón'],
        'vivienda.updated' => ['Modificó una vivienda', 'padrón'],
        'vivienda.deactivated' => ['Dio de baja una vivienda', 'padrón'],
        'vivienda.activated' => ['Reactivó una vivienda', 'padrón'],
        'familia.created' => ['Registró una familia', 'padrón'],
        'familia.updated' => ['Modificó una familia', 'padrón'],
        'familia.deactivated' => ['Dio de baja una familia', 'padrón'],
        'familia.activated' => ['Reactivó una familia', 'padrón'],
        'habitante.created' => ['Registró un habitante', 'padrón'],
        'habitante.updated' => ['Modificó un habitante', 'padrón'],
        'habitante.deactivated' => ['Dio de baja un habitante', 'padrón'],
        'habitante.activated' => ['Reactivó un habitante', 'padrón'],
        'cuota.created' => ['Registró una cuota', 'cuotas'],
        'cuota.updated' => ['Modificó una cuota', 'cuotas'],
        'periodo.generated' => ['Generó los cargos de un mes', 'cuotas'],
        'multa.created' => ['Asignó una multa', 'cuotas'],
        'pago.created' => ['Registró un pago', 'cobranza'],
        'ajustes.updated' => ['Cambió los ajustes de cobro', 'cuotas'],
        'asignacion.created' => ['Asignó una vivienda a un cobrador', 'cobradores'],
        'asignacion.ended' => ['Quitó una vivienda a un cobrador', 'cobradores'],
        'categoria_ingreso.created' => ['Creó una categoría de ingresos', 'ingresos'],
        'categoria_ingreso.updated' => ['Modificó una categoría de ingresos', 'ingresos'],
        'ingreso.created' => ['Registró un ingreso extra', 'ingresos'],
    ];

    public const CATEGORIES = ['acceso', 'usuarios', 'padrón', 'cuotas', 'cobranza', 'ingresos', 'cobradores', 'equipos', 'comunidad', 'sincronización', 'anulaciones'];

    public static function label(string $action): string
    {
        return self::ACTIONS[$action][0] ?? $action;
    }

    public static function category(string $action): string
    {
        return self::ACTIONS[$action][1] ?? 'otros';
    }

    /** @return list<string> acciones de un tipo */
    public static function actionsIn(string $category): array
    {
        return array_keys(array_filter(self::ACTIONS, fn ($a) => $a[1] === $category));
    }
}
