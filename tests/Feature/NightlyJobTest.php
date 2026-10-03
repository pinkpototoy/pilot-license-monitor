<?php

namespace Tests\Feature;

use App\Models\ComplianceSnapshot;
use App\Models\JobRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NightlyJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->freezeToday('2026-10-03');
    }

    public function test_nightly_run_is_idempotent_and_recorded(): void
    {
        $s = $this->student();
        $this->credential($s, 'SPL', 'SPL-1', '2025-01-01', '2026-10-20');
        $this->credential($s, 'MED', 'MC-1', '2026-01-01', '2027-01-01');

        $this->artisan('compliance:evaluate')->assertSuccessful();
        $this->artisan('compliance:evaluate')->assertSuccessful();

        $this->assertSame(2, ComplianceSnapshot::whereDate('snapshot_date', '2026-10-03')->count());
        $this->assertSame(2, JobRun::where('status', 'succeeded')->count());
        $snap = ComplianceSnapshot::where('validity_status', 'expiring_soon')->first();
        $this->assertSame('at_risk', $snap->compliance_state);
    }

    public function test_snapshots_answer_point_in_time_questions(): void
    {
        $s = $this->student();
        $this->credential($s, 'SPL', 'SPL-2', '2025-01-01', '2026-10-05');
        $this->credential($s, 'MED', 'MC-2', '2026-01-01', '2027-01-01');
        $this->artisan('compliance:evaluate');
        $this->freezeToday('2026-10-06');
        $this->artisan('compliance:evaluate');

        $on = fn ($d) => ComplianceSnapshot::whereDate('snapshot_date', $d)->where('student_id', $s->id)->value('compliance_state');
        $this->assertSame('at_risk', $on('2026-10-03'));
        $this->assertSame('non_compliant', $on('2026-10-06'));
    }

    public function test_audit_verify_command(): void
    {
        $this->student();
        $this->artisan('audit:verify')->expectsOutputToContain('intact')->assertSuccessful();
    }
}
