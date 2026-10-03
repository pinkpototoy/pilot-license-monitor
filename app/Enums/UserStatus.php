<?php

namespace App\Enums;

enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Locked = 'locked';
    case Deactivated = 'deactivated';
}
