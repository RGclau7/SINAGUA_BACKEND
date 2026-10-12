<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Community;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCommunity(Community::factory()->create());
    }

    public function test_audit_log_cannot_be_modified(): void
    {
        $log = AuditLog::record('prueba', ['created_at' => now()]);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'otra']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $log = AuditLog::record('prueba', ['created_at' => now()]);

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
