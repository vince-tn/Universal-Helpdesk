<?php

namespace App\Libraries;

// rfc 6238 time based one time passwords, sha1, 30 second steps
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $raw): string
    {
        $bits = '';
        foreach (str_split($raw) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $raw === '' ? '' : $out;
    }

    public static function base32Decode(string $encoded): ?string
    {
        $clean = strtoupper(preg_replace('/[\s=-]/', '', $encoded) ?? '');

        if ($clean === '' || strspn($clean, self::ALPHABET) !== strlen($clean)) {
            return null;
        }

        $bits = '';
        foreach (str_split($clean) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    public static function code(string $key, int $counter, int $digits = self::DIGITS, string $algo = 'sha1'): string
    {
        $hash   = hash_hmac($algo, pack('J', $counter), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;

        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    // returns the matched step, or null when the code is wrong or already used
    public static function verify(string $key, string $code, ?int $lastStep = null, ?int $time = null, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $current = self::step($time);

        for ($i = -$window; $i <= $window; $i++) {
            $step = $current + $i;

            if ($step < 0 || ($lastStep !== null && $step <= $lastStep)) {
                continue;
            }

            if (hash_equals(self::code($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        $query = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?' . $query;
    }
}
