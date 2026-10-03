<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Student = 'student';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Staff => 'Staff',
            self::Student => 'Student',
            self::Viewer => 'Viewer',
        };
    }

    /** FR-002 / BR-040: roles that must use MFA. */
    public function requiresMfa(): bool
    {
        return in_array($this, [self::Admin, self::Staff], true);
    }

    public function isStaffSide(): bool
    {
        return in_array($this, [self::Admin, self::Staff, self::Viewer], true);
    }
}
