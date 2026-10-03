<?php

namespace Tests\Unit;

use App\Domain\Compliance\ValidityCalculator;
use App\Enums\ValidityStatus as V;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SRS 13.1, AC-04, AC-05, BR-010 — every date boundary. */
class ValidityCalculatorTest extends TestCase
{
    public static function boundaries(): array
    {
        // [today, expiry, expected]  threshold 30 days
        return [
            'well ahead' => ['2026-10-03', '2027-10-03', V::Active],
            '31 days left is Active' => ['2026-10-03', '2026-11-03', V::Active],
            'AC-04: 30 days left is Expiring Soon' => ['2026-10-03', '2026-11-02', V::ExpiringSoon],
            '1 day left' => ['2026-10-03', '2026-10-04', V::ExpiringSoon],
            'expiry day itself is valid' => ['2026-10-03', '2026-10-03', V::ExpiringSoon],
            'AC-04: day after expiry is Expired' => ['2026-10-04', '2026-10-03', V::Expired],
            'long expired' => ['2027-05-01', '2026-10-03', V::Expired],
            'leap day expiry, on the day' => ['2028-02-29', '2028-02-29', V::ExpiringSoon],
            'leap day expiry, next day' => ['2028-03-01', '2028-02-29', V::Expired],
            'month end' => ['2026-12-31', '2027-01-31', V::Active],
            'year boundary' => ['2026-12-31', '2027-01-01', V::ExpiringSoon],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_status_at_boundaries(string $today, string $expiry, V $expected): void
    {
        $calc = new ValidityCalculator;
        $status = $calc->calculate(true, 30, 'SPL-1', CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse($expiry), CarbonImmutable::parse($today));
        $this->assertSame($expected, $status);
    }

    public function test_expiry_day_invalid_when_configured_so(): void
    {
        $calc = new ValidityCalculator(expiryDateIsValidDay: false);
        $this->assertSame(V::Expired, $calc->calculate(true, 30, 'X', null, CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2026-10-03')));
    }

    public function test_ac05_missing_expiry_or_number_is_incomplete(): void
    {
        $calc = new ValidityCalculator;
        $today = CarbonImmutable::parse('2026-10-03');
        $this->assertSame(V::IncompleteData, $calc->calculate(true, 30, 'SPL-1', $today->subYear(), null, $today));
        $this->assertSame(V::IncompleteData, $calc->calculate(true, 30, null, $today->subYear(), $today->addYear(), $today));
        $this->assertSame(V::IncompleteData, $calc->calculate(true, 30, '  ', $today->subYear(), $today->addYear(), $today));
        $this->assertSame(V::IncompleteData, $calc->calculate(true, 30, 'X', null, null, $today, hasPeriod: false));
    }

    public function test_future_issue_date_is_not_yet_valid(): void
    {
        $calc = new ValidityCalculator;
        $today = CarbonImmutable::parse('2026-10-03');
        $this->assertSame(V::NotYetValid, $calc->calculate(true, 30, 'X', $today->addDay(), $today->addYears(2), $today));
    }

    public function test_non_expiring_type(): void
    {
        $calc = new ValidityCalculator;
        $today = CarbonImmutable::parse('2026-10-03');
        $this->assertSame(V::Active, $calc->calculate(false, 30, null, $today->subYear(), null, $today));
        $this->assertSame(V::IncompleteData, $calc->calculate(false, 30, null, null, null, $today));
    }

    public function test_threshold_is_per_type(): void
    {
        $calc = new ValidityCalculator;
        $today = CarbonImmutable::parse('2026-10-03');
        $this->assertSame(V::ExpiringSoon, $calc->calculate(true, 60, 'X', null, $today->addDays(45), $today));
        $this->assertSame(V::Active, $calc->calculate(true, 30, 'X', null, $today->addDays(45), $today));
    }

    public function test_days_remaining_ignores_time_of_day_and_zone(): void
    {
        $exp = CarbonImmutable::parse('2026-10-10 00:00', 'Asia/Manila');
        $today = CarbonImmutable::parse('2026-10-03 23:59', 'UTC');
        $this->assertSame(7, ValidityCalculator::daysRemaining($exp, $today));
    }
}
