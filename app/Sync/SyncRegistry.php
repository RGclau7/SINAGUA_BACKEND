<?php

namespace App\Sync;

class SyncRegistry
{
    /** @var array<string, SyncHandler> */
    private array $handlers = [];

    public function register(string $entity, SyncHandler $handler): void
    {
        $this->handlers[$entity] = $handler;
    }

    public function handlerFor(string $entity): ?SyncHandler
    {
        return $this->handlers[$entity] ?? null;
    }

    /** @return list<string> */
    public function entities(): array
    {
        return array_keys($this->handlers);
    }
}
