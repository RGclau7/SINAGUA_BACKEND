<?php

namespace App\Padron;

use App\Models\Familia;
use App\Models\Vivienda;

/**
 * Adeudo pendiente de una familia o vivienda, en centavos. Lo calcula Cobranza (M7) a partir de
 * cargos y pagos; mientras no exista, nadie debe nada (NoDebts). Se usa para advertir antes de
 * una baja (CDU5, 3b).
 */
interface Debts
{
    public function owedByFamilia(Familia $familia): int;

    public function owedByVivienda(Vivienda $vivienda): int;
}
