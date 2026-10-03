<?php

namespace App\Enums;

/** SRS 13.1 — computed from dates, never typed in (BR-016). */
enum ValidityStatus: string
{
    case Active = 'active';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case IncompleteData = 'incomplete_data';
    case NotYetValid = 'not_yet_valid';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::ExpiringSoon => 'Expiring Soon',
            self::Expired => 'Expired',
            self::IncompleteData => 'Incomplete',
            self::NotYetValid => 'Not Yet Valid',
        };
    }

    /** UX-02 colour token. Always rendered together with label(). */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::ExpiringSoon => 'yellow',
            self::Expired => 'red',
            self::IncompleteData, self::NotYetValid => 'grey',
        };
    }
}
