<?php

namespace Tests\Unit;

use App\Domain\Compliance\ComplianceEvaluator;
use App\Enums\ComplianceState as C;
use App\Enums\ValidityStatus as V;
use PHPUnit\Framework\TestCase;

/** SRS 16 — precedence Non-Compliant > Data Issue > At Risk > Compliant. */
class ComplianceEvaluatorTest extends TestCase
{
    private ComplianceEvaluator $e;

    protected function setUp(): void
    {
        $this->e = new ComplianceEvaluator;
    }

    public function test_all_required_active_is_compliant(): void
    {
        $this->assertSame(C::Compliant, $this->e->evaluate(true, [1, 2], [1 => V::Active, 2 => V::Active]));
    }

    public function test_one_expiring_is_at_risk(): void
    {
        $this->assertSame(C::AtRisk, $this->e->evaluate(true, [1, 2], [1 => V::Active, 2 => V::ExpiringSoon]));
    }

    public function test_expired_or_missing_is_non_compliant_and_wins(): void
    {
        $this->assertSame(C::NonCompliant, $this->e->evaluate(true, [1, 2], [1 => V::Expired, 2 => V::IncompleteData]));
        $this->assertSame(C::NonCompliant, $this->e->evaluate(true, [1, 2], [1 => V::Active]));
    }

    public function test_incomplete_is_data_issue_over_at_risk(): void
    {
        $this->assertSame(C::DataIssue, $this->e->evaluate(true, [1, 2], [1 => V::ExpiringSoon, 2 => V::IncompleteData]));
    }

    public function test_extra_non_required_credentials_are_ignored(): void
    {
        $this->assertSame(C::Compliant, $this->e->evaluate(true, [1], [1 => V::Active, 9 => V::Expired]));
    }

    public function test_no_program_is_data_issue_and_inactive_is_not_monitored(): void
    {
        $this->assertSame(C::DataIssue, $this->e->evaluate(true, null, [1 => V::Active]));
        $this->assertSame(C::NotMonitored, $this->e->evaluate(false, [1], [1 => V::Expired]));
    }
}
