<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\SyncChange;
use App\Sync\Mutation;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRegistry;
use App\Sync\SyncRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->actingAsMemberOf();
        $this->device = Device::create([
            'id' => (string) Str::uuid(),
            'name' => 'Caja de prueba',
            'receipt_prefix' => 'E01',
            'registered_by' => $user->id,
        ]);
    }

    private function mutation(string $entity, string $op = 'create', array $payload = ['monto' => '150.00']): array
    {
        return [
            'mutation_id' => (string) Str::uuid(),
            'entity' => $entity,
            'entity_id' => (string) Str::uuid(),
            'op' => $op,
            'payload' => $payload,
            'occurred_at' => now()->subHour()->toIso8601String(),
        ];
    }

    private function push(array $mutations, ?string $deviceId = null)
    {
        return $this->postJson('/api/v1/sync/push', [
            'device_id' => $deviceId ?? $this->device->id,
            'mutations' => $mutations,
        ]);
    }

    private function registerHandler(string $entity, SyncPolicy $policy, ?callable $apply = null): void
    {
        app(SyncRegistry::class)->register($entity, new class($policy, $apply) implements SyncHandler
        {
            public function __construct(private SyncPolicy $policy, private $apply) {}

            public function policy(): SyncPolicy
            {
                return $this->policy;
            }

            public function apply(Mutation $mutation): ?array
            {
                return $this->apply ? ($this->apply)($mutation) : ['id' => $mutation->entityId, ...$mutation->payload];
            }
        });
    }

    public function test_unknown_entity_is_rejected_and_logged(): void
    {
        $m = $this->mutation('desconocida');

        $this->push([$m])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'entity_not_supported');

        $this->assertTrue(AuditLog::where('action', 'sync.rejected')->where('entity_id', $m['entity_id'])->exists());
        $this->assertSame(0, SyncChange::where('entity', '!=', 'bitacora')->count());
    }

    public function test_accepted_mutation_appears_in_pull(): void
    {
        $this->registerHandler('pagos', SyncPolicy::AppendOnly);
        $m = $this->mutation('pagos');

        $this->push([$m])->assertJsonPath('data.results.0.status', 'accepted');

        $this->getJson('/api/v1/sync/pull?device_id='.$this->device->id.'&cursor=0')
            ->assertOk()
            ->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.changes.0.entity', 'pagos')
            ->assertJsonPath('data.changes.0.entity_id', $m['entity_id'])
            ->assertJsonPath('data.changes.0.payload.monto', '150.00')
            ->assertJsonPath('data.has_more', false);
    }

    public function test_append_only_rejects_updates(): void
    {
        $this->registerHandler('pagos', SyncPolicy::AppendOnly);

        $this->push([$this->mutation('pagos', 'update')])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'append_only');
    }

    public function test_resending_same_mutation_is_idempotent(): void
    {
        $calls = 0;
        $this->registerHandler('pagos', SyncPolicy::AppendOnly, function (Mutation $m) use (&$calls) {
            $calls++;

            return ['id' => $m->entityId];
        });
        $m = $this->mutation('pagos');

        $this->push([$m])->assertJsonPath('data.results.0.status', 'accepted');
        $this->push([$m])->assertJsonPath('data.results.0.status', 'accepted');

        $this->assertSame(1, $calls);
        $this->assertSame(1, SyncChange::where('entity', '!=', 'bitacora')->count());
    }

    public function test_server_wins_conflict_reverts_partial_writes(): void
    {
        $this->registerHandler('viviendas', SyncPolicy::ServerWins, function (Mutation $m) {
            AuditLog::record('escritura.parcial', ['entity_id' => $m->entityId, 'created_at' => now()]);
            throw new SyncRejected('conflict', 'El servidor tiene una versión más reciente.');
        });

        $this->push([$this->mutation('viviendas', 'update')])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'conflict');

        $this->assertFalse(AuditLog::where('action', 'escritura.parcial')->exists());
        $this->assertTrue(AuditLog::where('action', 'sync.rejected')->exists());
    }

    public function test_pull_pages_with_cursor(): void
    {
        $this->registerHandler('pagos', SyncPolicy::AppendOnly);
        $this->push([$this->mutation('pagos'), $this->mutation('pagos'), $this->mutation('pagos')]);

        $first = $this->getJson('/api/v1/sync/pull?device_id='.$this->device->id.'&cursor=0&limit=2')
            ->assertJsonCount(2, 'data.changes')
            ->assertJsonPath('data.has_more', true);

        $this->getJson('/api/v1/sync/pull?device_id='.$this->device->id.'&cursor='.$first->json('data.next_cursor').'&limit=2')
            ->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.has_more', false);
    }

    public function test_unregistered_device_gets_409(): void
    {
        $this->push([], (string) Str::uuid())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'device_not_registered');
    }
}
