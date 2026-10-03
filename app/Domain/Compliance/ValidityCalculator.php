<?php

namespace App\Domain\Compliance;

use App\Enums\ValidityStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * SRS 13.1 — pure function from dates to a validity status. No database access,
 * so every boundary can be unit-tested exhaustively.
 *
 *   Incomplete Data  expiring type with no period, no expiry date or no license number
 *   Not Yet Valid    issue date after today
 *   Expired          today after the expiry date (the expiry date itself is valid — confirm)
 *   Expiring Soon    0 ≤ days remaining ≤ threshold   (AC-04: "expiring in 30 days" is Expiring Soon)
 *   Active           days remaining > threshold
 */
final class ValidityCalculator
{
    public function __construct(private readonly bool $expiryDateIsValidDay = true) {}

    public function calculate(
        bool $typeExpires,
        int $expiringSoonDays,
        ?string $licenseNumber,
        ?DateTimeInterface $issueDate,
        ?DateTimeInterface $expiryDate,
        DateTimeInterface $today,
        bool $hasPeriod = true,
    ): ValidityStatus {
        $today = CarbonImmutable::instance($today)->startOfDay();

        if (! $hasPeriod) {
            return ValidityStatus::IncompleteData;
        }

        if ($issueDate !== null && CarbonImmutable::instance($issueDate)->startOfDay()->greaterThan($today)) {
            return ValidityStatus::NotYetValid;
        }

        if (! $typeExpires) {
            return $issueDate === null ? ValidityStatus::IncompleteData : ValidityStatus::Active;
        }

        if ($expiryDate === null || $licenseNumber === null || trim($licenseNumber) === '') {
            return ValidityStatus::IncompleteData;
        }

        $daysRemaining = self::daysRemaining($expiryDate, $today);
        $expired = $this->expiryDateIsValidDay ? $daysRemaining < 0 : $daysRemaining <= 0;

        if ($expired) {
            return ValidityStatus::Expired;
        }

        return $daysRemaining <= $expiringSoonDays ? ValidityStatus::ExpiringSoon : ValidityStatus::Active;
    }

    /** Whole calendar days from today to the expiry date (negative once expired). */
    public static function daysRemaining(DateTimeInterface $expiryDate, DateTimeInterface $today): int
    {
        $from = CarbonImmutable::parse(CarbonImmutable::instance($today)->format('Y-m-d'), 'UTC');
        $to = CarbonImmutable::parse(CarbonImmutable::instance($expiryDate)->format('Y-m-d'), 'UTC');

        return (int) $from->diffInDays($to, false);
    }
}
