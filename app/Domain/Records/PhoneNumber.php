<?php

namespace App\Domain\Records;

/** Normalizes Philippine mobile numbers to +63XXXXXXXXXX; leaves other formats as entered. */
final class PhoneNumber
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);

        return match (true) {
            strlen($digits) === 11 && str_starts_with($digits, '09') => '+63'.substr($digits, 1),
            strlen($digits) === 12 && str_starts_with($digits, '639') => '+'.$digits,
            strlen($digits) === 10 && str_starts_with($digits, '9') => '+63'.$digits,
            default => trim($raw),
        };
    }
}
