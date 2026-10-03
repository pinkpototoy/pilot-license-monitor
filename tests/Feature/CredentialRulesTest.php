<?php

namespace Tests\Feature;

use App\Domain\Compliance\ComplianceEngine;
use App\Domain\DomainRuleViolation;
use App\Domain\Records\CredentialService;
use App\Enums\ComplianceState;
use App\Enums\Role;
use App\Enums\ValidityStatus;
use App\Models\AuditLog;
use App\Models\CredentialType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->freezeToday('2026-10-03');
    }

    private function expectRule(string $field, string $ruleId, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$ruleId}");
        } catch (DomainRuleViolation $e) {
            $this->assertArrayHasKey($field, $e->errors);
            $this->assertStringContainsString($ruleId, $e->errors[$field]);
        }
    }

    public function test_status_is_computed_on_save_and_compliance_follows(): void
    {
        $s = $this->student();
        $c = $this->credential($s, 'SPL', 'SPL-1', '2025-01-01', '2026-11-02');   // 30 days left
        $this->assertSame(ValidityStatus::ExpiringSoon, $c->current_status);
        $this->assertSame(ComplianceState::NonCompliant, $s->refresh()->compliance_state); // MED missing

        $this->credential($s, 'MED', 'MC-1', '2026-01-01', '2027-01-01');
        $this->assertSame(ComplianceState::AtRisk, $s->refresh()->compliance_state);
    }

    public function test_ac04_status_changes_without_manual_action(): void
    {
        $s = $this->student();
        $c = $this->credential($s, 'SPL', 'SPL-2', '2025-01-01', '2026-10-05');
        $this->assertSame(ValidityStatus::ExpiringSoon, $c->current_status);

        $this->freezeToday('2026-10-06');
        app(ComplianceEngine::class)->runNightly();
        $this->assertSame(ValidityStatus::Expired, $c->refresh()->current_status);
        $this->assertTrue(AuditLog::where('action', 'credential.status_changed')->where('actor_role', 'system')->exists());
    }

    public function test_br010_ac05_missing_expiry_is_saved_as_incomplete(): void
    {
        $c = $this->credential($this->student(), 'SPL', 'SPL-3', '2025-01-01', null);
        $this->assertSame(ValidityStatus::IncompleteData, $c->current_status);
    }

    public function test_br011_expiry_must_follow_issue(): void
    {
        $s = $this->student();
        $this->expectRule('expiry_date', 'BR-011', fn () => $this->credential($s, 'SPL', 'X1', '2026-05-01', '2026-05-01'));
    }

    public function test_br012_issue_date_not_more_than_one_day_ahead(): void
    {
        $s = $this->student();
        $this->expectRule('issue_date', 'BR-012', fn () => $this->credential($s, 'SPL', 'X2', '2026-10-05', '2028-10-05'));
        // Tomorrow is allowed.
        $this->assertNotNull($this->credential($s, 'SPL', 'X3', '2026-10-04', '2028-10-04'));
    }

    public function test_br013_long_validity_needs_confirmation(): void
    {
        $s = $this->student();
        $type = CredentialType::where('code', 'MED')->first();   // max 60 months in reference data
        try {
            app(CredentialService::class)->record($s, $type, 'M1', '2026-01-01', '2032-01-02', $this->user());
            $this->fail('Expected BR-013');
        } catch (DomainRuleViolation $e) {
            $this->assertTrue($e->needsConfirmation);
        }
        $c = app(CredentialService::class)->record($s, $type, 'M1', '2026-01-01', '2032-01-02', $this->user(), confirmed: true);
        $this->assertSame(ValidityStatus::Active, $c->current_status);
    }

    public function test_br014_license_number_cannot_belong_to_two_students(): void
    {
        $this->credential($this->student(), 'SPL', 'SPL-777', '2025-01-01', '2027-01-01');
        $other = $this->student();
        $this->expectRule('license_number', 'BR-014', fn () => $this->credential($other, 'SPL', 'spl-777', '2025-01-01', '2027-01-01'));
    }

    public function test_br015_one_current_credential_per_type(): void
    {
        $s = $this->student();
        $this->credential($s, 'SPL', 'SPL-8', '2025-01-01', '2027-01-01');
        $this->expectRule('credential_type_id', 'BR-015', fn () => $this->credential($s, 'SPL', 'SPL-9', '2025-01-01', '2027-01-01'));
    }

    public function test_iso_dates_only_to_avoid_day_month_confusion(): void
    {
        $s = $this->student();
        $this->expectRule('issue_date', 'YYYY-MM-DD', fn () => $this->credential($s, 'SPL', 'X4', '03/04/2025', '2027-01-01'));
        $this->expectRule('expiry_date', 'calendar date', fn () => $this->credential($s, 'SPL', 'X5', '2025-01-01', '2027-02-30'));
    }

    public function test_fr022_number_pattern_when_configured(): void
    {
        CredentialType::where('code', 'SPL')->update(['number_pattern' => 'SPL-\d{5}']);
        $s = $this->student();
        $this->expectRule('license_number', 'FR-022', fn () => $this->credential($s, 'SPL', 'ABC', '2025-01-01', '2027-01-01'));
        $this->assertNotNull($this->credential($s, 'SPL', 'spl-12345', '2025-01-01', '2027-01-01'));
    }

    public function test_br017_ac14_correction_requires_reason_and_is_audited_with_before_after(): void
    {
        $s = $this->student();
        $c = $this->credential($s, 'SPL', 'SPL-10', '2025-03-15', '2027-03-15');
        $svc = app(CredentialService::class);
        $staff = $this->user();

        $this->expectRule('reason', 'BR-017', fn () => $svc->correctCurrentPeriod($c, 'SPL-10', '2025-03-15', '2027-09-15', ' ', $staff));

        $svc->correctCurrentPeriod($c, 'SPL-10', '2025-03-15', '2027-09-15', 'Corrected typo from certificate', $staff);
        $log = AuditLog::where('action', 'credential.period_corrected')->latest('id')->first();
        $this->assertSame($staff->id, $log->actor_user_id);
        $this->assertEquals(['old' => '2027-03-15', 'new' => '2027-09-15'], $log->changes['expiry_date']);
        $this->assertSame('Corrected typo from certificate', $log->remarks);
        $this->assertSame(1, $c->periods()->count(), 'A correction must not create a new period');
    }

    public function test_fr023_ac12_new_period_keeps_history(): void
    {
        $s = $this->student();
        $c = $this->credential($s, 'SPL', 'SPL-11', '2024-10-10', '2026-10-10');
        $this->assertSame(ValidityStatus::ExpiringSoon, $c->current_status);

        app(CredentialService::class)->addPeriod($c, 'SPL-11B', '2026-10-01', '2028-10-01', $this->user());
        $c->refresh();

        $this->assertSame(ValidityStatus::Active, $c->current_status);
        $this->assertSame('SPL-11B', $c->license_number);                     // EC-16
        $this->assertSame(2, $c->periods()->count());
        $old = $c->periods()->whereNotNull('superseded_at')->first();
        $this->assertSame('SPL-11', $old->license_number);
        $this->assertSame($c->current_period_id, $old->superseded_by);
    }

    public function test_new_period_not_later_than_current_needs_confirmation(): void
    {
        $c = $this->credential($this->student(), 'SPL', 'SPL-12', '2025-01-01', '2027-01-01');
        try {
            app(CredentialService::class)->addPeriod($c, null, '2025-06-01', '2026-12-01', $this->user());
            $this->fail('Expected confirmation');
        } catch (DomainRuleViolation $e) {
            $this->assertTrue($e->needsConfirmation);
        }
    }

    public function test_renewal_recorded_early_keeps_previous_period_in_force(): void
    {
        $c = $this->credential($this->student(), 'SPL', 'SPL-13', '2024-10-20', '2026-10-20');
        app(CredentialService::class)->addPeriod($c, null, '2026-10-04', '2028-10-04', $this->user()); // starts tomorrow
        $this->assertSame(ValidityStatus::ExpiringSoon, $c->refresh()->current_status);
    }

    public function test_br018_override_is_admin_only_and_ends_when_dates_change(): void
    {
        $c = $this->credential($this->student(), 'SPL', 'SPL-14', '2024-01-01', '2026-09-01');
        $this->assertSame(ValidityStatus::Expired, $c->current_status);
        $svc = app(CredentialService::class);

        $this->expectRule('override_status', 'BR-018', fn () => $svc->applyOverride($c, ValidityStatus::Active, 'x', null, $this->user(Role::Staff)));

        $admin = $this->user(Role::Admin);
        $svc->applyOverride($c, ValidityStatus::Active, 'Authority confirmed renewal by phone; paper copy pending', '2026-10-10', $admin);
        $this->assertSame(ValidityStatus::Active, $c->refresh()->current_status);

        // Override lapses after its end date.
        $this->freezeToday('2026-10-11');
        app(ComplianceEngine::class)->runNightly();
        $this->assertSame(ValidityStatus::Expired, $c->refresh()->current_status);

        // A new override ends as soon as the dates change.
        $this->freezeToday('2026-10-12');
        $svc->applyOverride($c, ValidityStatus::Active, 'Second exception', null, $admin);
        $svc->correctCurrentPeriod($c, 'SPL-14', '2024-01-01', '2026-09-02', 'Typo', $admin);
        $this->assertNull($c->activeOverride()->first());
        $this->assertSame(ValidityStatus::Expired, $c->refresh()->current_status);
    }
}
