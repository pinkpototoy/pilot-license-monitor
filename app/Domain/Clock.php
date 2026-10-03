<?php

namespace App\Domain;

use Carbon\CarbonImmutable;

/**
 * NFR-017: "today" is always the calendar date in the organization's time zone,
 * never the server's. Tests control it with Carbon::setTestNow().
 */
final class Clock
{
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('splms.timezone'))->startOfDay();
    }

    public static function timezone(): string
    {
        return config('splms.timezone');
    }
}
