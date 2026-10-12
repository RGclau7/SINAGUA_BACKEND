<?php

namespace App\Sync;

use Carbon\CarbonImmutable;

/**
 * Una escritura hecha en un equipo (posiblemente sin conexión) que llega en /sync/push.
 */
final readonly class Mutation
{
    public const OP_CREATE = 'create';

    public const OP_UPDATE = 'update';

    public const OP_DELETE = 'delete';

    /** Anulación de un movimiento con motivo (no lo borra). */
    public const OP_VOID = 'void';

    public const OPS = [self::OP_CREATE, self::OP_UPDATE, self::OP_DELETE, self::OP_VOID];

    public function __construct(
        public string $mutationId,
        public string $entity,
        public string $entityId,
        public string $op,
        public array $payload,
        public ?CarbonImmutable $occurredAt,
        public string $deviceId,
        public ?string $userId,
    ) {}
}
