<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditLogger;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_ac15_entries_cannot_be_modified_through_the_app(): void
    {
        $log = app(AuditLogger::class)->record('test.action', 'thing', 1);
        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_ac15_database_blocks_update_and_delete_even_with_raw_sql(): void
    {
        app(AuditLogger::class)->record('test.action', 'thing', 1);
        foreach (['UPDATE audit_logs SET action = \'x\'', 'DELETE FROM audit_logs'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Statement should have been blocked: {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame(1, AuditLog::count());
    }

    public function test_hash_chain_verifies_and_detects_tampering(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('a.one', 'thing', 1, changes: ['x' => ['old' => 1, 'new' => 2]]);
        $audit->record('a.two', 'thing', 2, remarks: 'second');
        $audit->record('a.three', 'thing', 3);
        $this->assertTrue($audit->verifyChain()['ok']);

        // Simulate someone with table-owner rights bypassing the trigger (self-review #9).
        DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_no_update');
        DB::table('audit_logs')->where('action', 'a.two')->update(['remarks' => 'edited']);
        DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_no_update');

        $result = $audit->verifyChain();
        $this->assertFalse($result['ok']);
        $this->assertSame(AuditLog::where('action', 'a.two')->value('id'), $result['broken_at']);
    }

    public function test_secrets_are_redacted(): void
    {
        $log = app(AuditLogger::class)->record('user.updated', 'user', 1, changes: ['password' => ['old' => 'a', 'new' => 'b']]);
        $this->assertSame('[redacted]', $log->fresh()->changes['password']['new']);
    }
}
