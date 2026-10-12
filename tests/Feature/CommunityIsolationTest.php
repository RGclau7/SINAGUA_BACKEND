<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Device;
use App\Models\SyncChange;
use App\Models\SyncMutation;
use App\Models\User;
use App\Sync\Mutation;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRegistry;
use App\Tenancy\CurrentCommunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Una comunidad no puede leer ni escribir datos de otra.
 */
class CommunityIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Community $a;

    private Community $b;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Community::factory()->create(['name' => 'San Isidro']);
        $this->b = Community::factory()->create(['name' => 'La Cañada']);
        $this->userA = User::factory()->forCommunity($this->a)->create();
        $this->userB = User::factory()->forCommunity($this->b)->create();

        app(SyncRegistry::class)->register('pagos', new class implements SyncHandler
        {
            public function policy(): SyncPolicy
            {
                return SyncPolicy::AppendOnly;
            }

            public function apply(Mutation $mutation): ?array
            {
                return ['id' => $mutation->entityId, ...$mutation->payload];
            }
        });
    }

    private function registerDevice(User $user, string $name = 'Caja'): string
    {
        Sanctum::actingAs($user);
        $id = (string) Str::uuid();
        $this->postJson('/api/v1/devices', ['id' => $id, 'name' => $name])->assertCreated();

        return $id;
    }

    private function pago(array $payload = ['monto' => '100.00']): array
    {
        return [
            'mutation_id' => (string) Str::uuid(),
            'entity' => 'pagos',
            'entity_id' => (string) Str::uuid(),
            'op' => 'create',
            'payload' => $payload,
        ];
    }

    public function test_receipt_prefixes_are_unique_per_community(): void
    {
        Sanctum::actingAs($this->userA);
        $this->postJson('/api/v1/devices', ['id' => (string) Str::uuid(), 'name' => 'A1'])->assertJsonPath('data.receipt_prefix', 'E01');
        $this->postJson('/api/v1/devices', ['id' => (string) Str::uuid(), 'name' => 'A2'])->assertJsonPath('data.receipt_prefix', 'E02');

        Sanctum::actingAs($this->userB);
        $this->postJson('/api/v1/devices', ['id' => (string) Str::uuid(), 'name' => 'B1'])
            ->assertJsonPath('data.receipt_prefix', 'E01')
            ->assertJsonPath('data.community_id', $this->b->id);
    }

    public function test_community_comes_from_user_not_from_request(): void
    {
        Sanctum::actingAs($this->userA);
        $id = (string) Str::uuid();

        $this->postJson('/api/v1/devices', ['id' => $id, 'name' => 'Caja', 'community_id' => $this->b->id])
            ->assertCreated()
            ->assertJsonPath('data.community_id', $this->a->id);
    }

    public function test_device_of_other_community_cannot_be_used_or_reregistered(): void
    {
        $deviceA = $this->registerDevice($this->userA);

        Sanctum::actingAs($this->userB);
        $this->postJson('/api/v1/sync/push', ['device_id' => $deviceA, 'mutations' => [$this->pago()]])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_other_community');
        $this->getJson("/api/v1/sync/pull?device_id={$deviceA}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_other_community');
        $this->postJson('/api/v1/devices', ['id' => $deviceA, 'name' => 'Robado'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'device_other_community');

        $this->assertSame(0, app(CurrentCommunity::class)->runAsSystem(fn () => SyncMutation::count()));
    }

    public function test_pull_only_returns_changes_of_own_community(): void
    {
        $deviceA = $this->registerDevice($this->userA);
        $deviceB = $this->registerDevice($this->userB);

        Sanctum::actingAs($this->userA);
        $pagoA = $this->pago(['monto' => '150.00']);
        $this->postJson('/api/v1/sync/push', ['device_id' => $deviceA, 'mutations' => [$pagoA]])
            ->assertJsonPath('data.results.0.status', 'accepted');

        Sanctum::actingAs($this->userB);
        $this->postJson('/api/v1/sync/push', ['device_id' => $deviceB, 'mutations' => [$this->pago(['monto' => '999.00'])]])
            ->assertJsonPath('data.results.0.status', 'accepted');

        $pullB = $this->getJson("/api/v1/sync/pull?device_id={$deviceB}&cursor=0")->assertOk();
        $this->assertCount(1, $pullB->json('data.changes'));
        $this->assertSame('999.00', $pullB->json('data.changes.0.payload.monto'));

        Sanctum::actingAs($this->userA);
        $pullA = $this->getJson("/api/v1/sync/pull?device_id={$deviceA}&cursor=0")->assertOk();
        $this->assertCount(1, $pullA->json('data.changes'));
        $this->assertSame($pagoA['entity_id'], $pullA->json('data.changes.0.entity_id'));
    }

    public function test_reused_mutation_id_from_other_community_is_rejected_without_leaking(): void
    {
        $deviceA = $this->registerDevice($this->userA);
        $deviceB = $this->registerDevice($this->userB);
        $pago = $this->pago(['monto' => '150.00']);

        Sanctum::actingAs($this->userA);
        $this->postJson('/api/v1/sync/push', ['device_id' => $deviceA, 'mutations' => [$pago]]);

        Sanctum::actingAs($this->userB);
        $this->postJson('/api/v1/sync/push', ['device_id' => $deviceB, 'mutations' => [$pago]])
            ->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.0.code', 'duplicate_mutation_id')
            ->assertJsonMissingPath('data.results.0.entity');

        $this->useCommunity($this->b);
        $this->assertSame(0, SyncChange::where('entity', '!=', 'bitacora')->count());
    }

    public function test_models_only_see_current_community(): void
    {
        $this->registerDevice($this->userA, 'Caja A');
        $this->registerDevice($this->userB, 'Caja B');

        $this->useCommunity($this->a);
        $this->assertSame(['Caja A'], Device::pluck('name')->all());
        $this->assertSame(1, AuditLog::count());
        $this->assertTrue(AuditLog::get()->every(fn (AuditLog $l) => $l->community_id === $this->a->id));

        $this->useCommunity(null);
        $this->assertSame(0, Device::count(), 'Sin comunidad activa no se ve nada');
    }

    public function test_models_cannot_be_written_into_other_community(): void
    {
        $this->useCommunity($this->a);

        $device = new Device(['id' => (string) Str::uuid(), 'name' => 'X', 'receipt_prefix' => 'E09']);
        $device->community_id = $this->b->id;
        $this->expectException(LogicException::class);
        $device->save();
    }

    public function test_models_cannot_move_to_other_community(): void
    {
        $this->useCommunity($this->a);
        $device = Device::create(['id' => (string) Str::uuid(), 'name' => 'X', 'receipt_prefix' => 'E09']);

        $device->community_id = $this->b->id;
        $this->expectException(LogicException::class);
        $device->save();
    }

    public function test_creating_without_community_fails(): void
    {
        $this->useCommunity(null);

        $this->expectException(LogicException::class);
        AuditLog::record('algo');
    }

    public function test_inactive_community_is_blocked(): void
    {
        $this->a->update(['is_active' => false]);
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/devices', ['id' => (string) Str::uuid(), 'name' => 'Caja'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'community_inactive');
    }
}
