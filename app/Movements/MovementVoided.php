<?php

namespace App\Movements;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Se dispara al anular un movimiento. M7 (cobranza) y M10 (entregas y cortes) lo escuchan
 * para recalcular adeudos y cortes (CDU23, paso 4).
 */
final readonly class MovementVoided
{
    public function __construct(
        public Model $movement,
        public User $actor,
        public string $reason,
    ) {}
}
