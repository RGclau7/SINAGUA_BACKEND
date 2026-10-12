<?php

namespace App\Sync;

enum SyncPolicy: string
{
    /** Movimientos de dinero (pagos, entregas, gastos): solo se anexan o se anulan con motivo. */
    case AppendOnly = 'append_only';

    /** Catálogos (viviendas, cuotas, asignaciones): si hay conflicto, gana el servidor. */
    case ServerWins = 'server_wins';
}
