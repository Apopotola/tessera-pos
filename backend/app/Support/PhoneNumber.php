<?php

namespace App\Support;

/**
 * Kenyan mobile numbers in one canonical form (+2547XXXXXXXX / +2541XXXXXXXX)
 * so "0712 345 678", "712345678" and "+254 712 345678" all match the same user.
 */
final class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        $national = match (true) {
            str_starts_with($digits, '254') && strlen($digits) === 12 => substr($digits, 3),
            str_starts_with($digits, '0') && strlen($digits) === 10 => substr($digits, 1),
            strlen($digits) === 9 => $digits,
            default => null,
        };

        if ($national === null || ! preg_match('/^[17]\d{8}$/', $national)) {
            return null;
        }

        return '+254'.$national;
    }
}
