<?php

namespace App\Enums;

enum PeriodSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Renewal = 'renewal';
}
