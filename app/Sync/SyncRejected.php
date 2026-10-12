<?php

namespace App\Sync;

use RuntimeException;

/**
 * La lanza un handler cuando el servidor no acepta la mutación (regla de negocio
 * o conflicto en un catálogo donde gana el servidor).
 */
class SyncRejected extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
