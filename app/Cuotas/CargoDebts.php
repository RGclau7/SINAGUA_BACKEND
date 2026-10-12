<?php

namespace App\Cuotas;

use App\Cobranza\CobranzaService;
use App\Models\Familia;
use App\Models\Vivienda;
use App\Padron\Debts;

/**
 * Adeudo = cargos (cuotas y multas) no anulados que aún no tienen un pago vigente.
 */
class CargoDebts implements Debts
{
    public function owedByFamilia(Familia $familia): int
    {
        return (int) CobranzaService::unpaid()->where('familia_id', $familia->id)->sum('amount_cents');
    }

    public function owedByVivienda(Vivienda $vivienda): int
    {
        return (int) CobranzaService::unpaid()->whereIn('familia_id', $vivienda->familias()->select('id'))->sum('amount_cents');
    }
}
