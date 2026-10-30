<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_an_event(): void
    {
        $entry = AuditLog::record(AuditEvent::UserSuspended, metadata: ['reason' => 'test']);

        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'event' => 'user.suspended']);
        $this->assertNotNull($entry->created_at);
    }

    public function test_entries_cannot_be_updated(): void
    {
        $entry = AuditLog::record(AuditEvent::UserSuspended);

        $this->expectException(LogicException::class);

        $entry->update(['event' => 'tampered']);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $entry = AuditLog::record(AuditEvent::UserSuspended);

        $this->expectException(LogicException::class);

        $entry->delete();
    }
}
