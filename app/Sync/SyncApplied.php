<?php

namespace App\Sync;

/**
 * Resultado de un handler cuando además del estado canónico tiene avisos para el usuario
 * (por ejemplo, "hay que reasignar las viviendas de este cobrador").
 */
final readonly class SyncApplied
{
    /**
     * @param  list<array{code: string, message: string}>  $notices
     */
    public function __construct(
        public ?array $canonical,
        public array $notices = [],
    ) {}
}
