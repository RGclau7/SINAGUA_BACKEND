<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de negocio con un código estable que el cliente puede interpretar.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}
