<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Comunidad de la petición en curso. La fija el middleware a partir del usuario
 * autenticado; nunca se toma de lo que envía el cliente.
 */
class CurrentCommunity
{
    private ?string $id = null;

    private bool $system = false;

    public function id(): ?string
    {
        return $this->id;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function set(?string $communityId): void
    {
        $this->id = $communityId;
        $this->syncDatabase();
    }

    /**
     * Ejecuta sin filtro de comunidad. Solo para operaciones del sistema o del
     * superadministrador (crear comunidades, comprobar a quién pertenece un equipo).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAsSystem(callable $callback): mixed
    {
        $previous = $this->system;
        $this->system = true;
        $this->syncDatabase();

        try {
            return $callback();
        } finally {
            $this->system = $previous;
            $this->syncDatabase();
        }
    }

    /**
     * Pasa el contexto a PostgreSQL para las políticas de Row-Level Security.
     */
    private function syncDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select("select set_config('app.community_id', ?, false), set_config('app.system', ?, false)", [
            $this->id ?? '',
            $this->system ? 'on' : 'off',
        ]);
    }
}
