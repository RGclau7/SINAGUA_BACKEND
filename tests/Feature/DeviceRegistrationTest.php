<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_get_sequential_receipt_prefixes(): void
    {
        $this->actingAsMemberOf();

        $this->postJson('/api/v1/devices', ['id' => (string) Str::uuid(), 'name' => 'Caja 1'])
            ->assertCreated()
            ->assertJsonPath('data.receipt_prefix', 'E01')
            ->assertJsonPath('data.is_active', true);

        $this->postJson('/api/v1/devices', ['id' => (string) Str::uuid(), 'name' => 'Caja 2'])
            ->assertCreated()
            ->assertJsonPath('data.receipt_prefix', 'E02');

        $this->assertSame(2, AuditLog::where('action', 'device.registered')->count());
    }

    public function test_registration_is_idempotent(): void
    {
        $this->actingAsMemberOf();
        $id = (string) Str::uuid();

        $this->postJson('/api/v1/devices', ['id' => $id, 'name' => 'Caja 1'])->assertCreated();
        $this->postJson('/api/v1/devices', ['id' => $id, 'name' => 'Otro nombre'])
            ->assertOk()
            ->assertJsonPath('data.receipt_prefix', 'E01')
            ->assertJsonPath('data.name', 'Caja 1');
    }
}
