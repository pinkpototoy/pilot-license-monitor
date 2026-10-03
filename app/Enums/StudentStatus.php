<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Graduated = 'graduated';
    case Withdrawn = 'withdrawn';
    case Deactivated = 'deactivated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnLeave => 'On leave',
            self::Graduated => 'Graduated',
            self::Withdrawn => 'Withdrawn',
            self::Deactivated => 'Deactivated',
        };
    }

    /** BR-004: only these students are monitored and reminded. */
    public function isMonitored(): bool
    {
        return in_array($this, [self::Active, self::OnLeave], true);
    }
}
