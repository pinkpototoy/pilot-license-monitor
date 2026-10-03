<?php

namespace App\Enums;

/** SRS 13.2 — workflow status, separate from validity. */
enum RenewalCaseStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderVerification = 'under_verification';
    case NeedsCorrection = 'needs_correction';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Renewal Submitted',
            self::UnderVerification => 'Under Verification',
            self::NeedsCorrection => 'Needs Correction',
            self::Resubmitted => 'Resubmitted',
            self::Approved => 'Documents Approved',
            self::Completed => 'Renewal Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'grey',
            self::Submitted, self::Resubmitted => 'blue',
            self::UnderVerification => 'orange',
            self::NeedsCorrection => 'red',
            self::Approved, self::Completed => 'green',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** @return list<string> values that count as "open" (BR-020). */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s->isOpen())
        ));
    }
}
