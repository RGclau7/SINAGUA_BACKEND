<?php

namespace App\Comunidad;

use App\Models\Community;
use App\Models\Periodo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Cuándo se cobra en una comunidad: cada cuántos meses (1, 2, 3, 4, 6 o 12; los periodos empiezan
 * en enero) y qué días del mes se recibe el pago. Un periodo se paga en los días de pago de su
 * primer mes; pasado el último, lleva multa.
 */
final class Calendario
{
    public const PERIODICIDADES = [1, 2, 3, 4, 6, 12];

    /** Mes en que empieza el periodo que contiene a ese mes. */
    public static function inicio(int $month, int $meses): int
    {
        return $month - (($month - 1) % max(1, $meses));
    }

    public static function esInicio(int $month, int $meses): bool
    {
        return self::inicio($month, $meses) === $month;
    }

    /** "octubre 2026" o "octubre–noviembre 2026". */
    public static function label(int $year, int $month, int $meses = 1): string
    {
        if ($meses <= 1) {
            return Periodo::monthLabel($year, $month);
        }

        $end = CarbonImmutable::create($year, $month, 1)->addMonths($meses - 1);
        $first = explode(' ', Periodo::monthLabel($year, $month))[0];

        return $first.'–'.Periodo::monthLabel($end->year, $end->month);
    }

    /** @return list<int> días del mes en que se recibe el pago */
    public static function dias(Community $community): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $community->dias_pago)), fn ($d) => $d >= 1 && $d <= 31)));
        sort($days);

        return $days;
    }

    public static function esDiaDePago(Community $community, CarbonInterface $date): bool
    {
        $days = self::dias($community);

        return $days === [] || in_array($date->day, $days, true)
            // Días que no existen en el mes (31 en noviembre) cuentan en el último día.
            || ($date->day === $date->daysInMonth && collect($days)->contains(fn ($d) => $d > $date->daysInMonth));
    }

    /** Último momento para pagar un periodo: el último día de pago de su primer mes, 23:59. */
    public static function limite(Community $community, int $year, int $month): CarbonImmutable
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $last = max(self::dias($community) ?: [$start->daysInMonth]);

        return $start->setDay(min($last, $start->daysInMonth))->endOfDay();
    }

    /** "del 1 al 10" o "los días 5 y 20". */
    public static function describeDias(array $days): string
    {
        if ($days === []) {
            return 'cualquier día';
        }
        $consecutive = $days === range($days[0], end($days));
        if ($consecutive && count($days) > 2) {
            return "del {$days[0]} al ".end($days);
        }
        if (count($days) === 1) {
            return "el día {$days[0]}";
        }
        $last = array_pop($days);

        return 'los días '.implode(', ', $days)." y {$last}";
    }
}
