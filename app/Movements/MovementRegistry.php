<?php

namespace App\Movements;

use Illuminate\Database\Eloquent\Model;

/**
 * Movimientos que se pueden anular, por nombre de entidad (el mismo de la sincronización).
 * Cada módulo financiero registra el suyo, p. ej. $registry->register('pagos', Pago::class).
 */
class MovementRegistry
{
    /** @var array<string, class-string<Model>> */
    private array $models = [];

    /** @param class-string<Model> $model */
    public function register(string $entity, string $model): void
    {
        $this->models[$entity] = $model;
    }

    /** @return class-string<Model>|null */
    public function modelFor(string $entity): ?string
    {
        return $this->models[$entity] ?? null;
    }
}
