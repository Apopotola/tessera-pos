<?php

namespace Modules\Auth\Support;

/**
 * Time-based one-time passwords (RFC 6238: HMAC-SHA1, 6 digits, 30-second steps), the
 * format every authenticator app (Google Authenticator, Microsoft Authenticator, Authy) reads.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    /** A new random secret, base32 (160 bits). */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** otpauth:// link shown as a QR code for the app to scan. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return "otpauth://totp/{$label}?".http_build_query(['secret' => $secret, 'issuer' => $issuer, 'digits' => self::DIGITS, 'period' => self::PERIOD]);
    }

    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The step the code belongs to (one step either side allows for clock drift), or null.
     * Steps at or before $lastStep are refused so a code cannot be replayed.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $now = self::step($timestamp);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (($lastStep === null || $step > $lastStep) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    private static function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }
}
