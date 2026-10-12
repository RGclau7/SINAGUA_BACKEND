<?php

namespace App\Padron;

use App\Models\Familia;
use App\Models\Vivienda;

class NoDebts implements Debts
{
    public function owedByFamilia(Familia $familia): int
    {
        return 0;
    }

    public function owedByVivienda(Vivienda $vivienda): int
    {
        return 0;
    }
}
