<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Device;
use App\Models\Roles;
use App\Models\User;
use App\Movements\MovementRegistry;
use App\Movements\MovementVoided;
use App\Movements\VoidsMovements;
use App\Sync\Mutation;
use App\Sync\SyncApplied;
use App\Sync\SyncHandler;
use App\Sync\SyncPolicy;
use App\Sync\SyncRegistry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Support\TestMovimiento;
use Tests\TestCase;

/**
 * RF23 y CDU23: anulación de movimientos con motivo; nada se borra.
 */
class VoidMovementTest extends TestCase
{
    use RefreshDatabase;

    private Community $community;

    private User $admin;

    private string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        TestMovimiento::createTable();
        $this->seed(RoleSeeder::class);

        $this->community = Community::factory()->create();
        $this->admin = User::factory()->forCommunity($this->community)->create()->assignRole(Roles::ADMIN);
        Sanctum::actingAs($this->admin);
        $this->useCommunity($this->community);
        $this->deviceId = (string) Str::uuid();
        Device::create(['id' => $this->deviceId, 'name' => 'Caja', 'receipt_prefix' => 'E01']);

        app(MovementRegistry::class)->register('pruebas', TestMovimiento::class);
        app(SyncRegistry::class)->register('pruebas', new class implements SyncHandler
        {
            use VoidsMovements;

            public function policy(): SyncPolicy
            {
                return SyncPolicy::AppendOnly;
            }

            public function apply(Mutation $mutation): SyncApplied
            {
                if ($mutation->op === Mutation::OP_VOID) {
                    return new SyncApplied($this->voidFromSync($mutation, TestMovimiento::class));
                }

                return new SyncApplied(TestMovimiento::create(['id' => $mutation->entityId, ...$mutation->payload])->toArray());
            }
        });
    }

    private function movimiento(): TestMovimiento
    {
        return TestMovimiento::create(['concepto' => 'Cuota de agua', 'monto' => '150.00']);
    }

    public function test_admin_voids_with_reason_and_it_is_logged(): void
    {
        Event::fake([MovementVoided::class]);
        $m = $this->movimiento();

        $this->postJson("/api/v1/movements/pruebas/{$m->id}/void", ['reason' => 'Se cobró dos veces'])
            ->assertOk()
            ->assertJsonPath('data.void_reason', 'Se cobró dos veces');

        $m->refresh();
        $this->assertTrue($m->isVoided());
        $this->assertSame($this->admin->id, $m->voided_by);
        $this->assertSame('150.00', $m->monto);
        $log = AuditLog::where('action', 'movement.voided')->firstOrFail();
        $this->assertSame('Se cobró dos veces', $log->data['reason']);
        $this->assertSame($m->id, $log->entity_id);
        Event::assertDispatched(MovementVoided::class, fn ($e) => $e->movement->is($m) && $e->reason === 'Se cobró dos veces');
    }

    public function test_reason_is_required(): void
    {
        $m = $this->movimiento();

        $this->postJson("/api/v1/movements/pruebas/{$m->id}/void", ['reason' => '  '])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'reason_required');
        $this->assertFalse($m->fresh()->isVoided());
    }

    public function test_already_voided_is_reported(): void
    {
        $m = $this->movimiento();
        $this->postJson("/api/v1/movements/pruebas/{$m->id}/void", ['reason' => 'Error de captura'])->assertOk();

        $this->postJson("/api/v1/movements/pruebas/{$m->id}/void", ['reason' => 'Otra vez'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'already_voided');
    }

    public function test_only_admins_can_void(): void
    {
        $m = $this->movimiento();
        Sanctum::actingAs(User::factory()->forCommunity($this->community)->create()->assignRole(Roles::COBRADOR));

        $this->postJson("/api/v1/movements/pruebas/{$m->id}/void", ['reason' => 'Lo quiero quitar'])->assertForbidden();
        $this->assertFalse($m->fresh()->isVoided());
    }

    public function test_movements_are_never_edited_or_deleted(): void
    {
        $m = $this->movimiento();

        try {
            $m->update(['monto' => '1.00']);
            $this->fail('No debió permitir modificar el monto.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $m->delete();
    }

    public function test_void_works_offline_through_sync(): void
    {
        $m = $this->movimiento();
        $push = fn (array $payload, string $op = 'void') => $this->postJson('/api/v1/sync/push', [
            'device_id' => $this->deviceId,
            'mutations' => [['mutation_id' => (string) Str::uuid(), 'entity' => 'pruebas', 'entity_id' => $m->id, 'op' => $op, 'payload' => $payload]],
        ]);

        $push(['void_reason' => ''])->assertJsonPath('data.results.0.code', 'reason_required');
        $push(['monto' => '1.00'], 'update')->assertJsonPath('data.results.0.code', 'append_only');
        $push(['void_reason' => 'Recibo duplicado'])->assertJsonPath('data.results.0.status', 'accepted');
        $push(['void_reason' => 'Otra vez'])->assertJsonPath('data.results.0.code', 'already_voided');

        $this->assertSame('Recibo duplicado', $m->fresh()->void_reason);
        $this->assertSame($this->deviceId, AuditLog::where('action', 'movement.voided')->first()->device_id);
    }

    public function test_cannot_void_movement_of_other_community(): void
    {
        $other = Community::factory()->create();
        $this->useCommunity($other);
        $ajeno = $this->movimiento();
        $this->useCommunity($this->community);

        $this->postJson("/api/v1/movements/pruebas/{$ajeno->id}/void", ['reason' => 'No es mío'])->assertNotFound();
    }
}
