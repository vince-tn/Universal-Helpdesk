<?php

namespace App\Libraries;

// rules every new password must pass
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    // short list of passwords that show up first in breach dumps
    public const COMMON = [
        '123456789012', '1234567890123', '12345678901234', '111111111111', '000000000000',
        'password1234', 'password12345', 'password123!', 'passw0rd1234', 'p@ssw0rd1234',
        'qwertyuiop12', 'qwerty123456', 'qwertyuiop123', '1q2w3e4r5t6y', 'q1w2e3r4t5y6',
        'iloveyou1234', 'welcome12345', 'welcome@1234', 'letmein12345', 'abc123456789',
        'abcdefghijkl', 'administrator', 'admin1234567', 'changeme1234', 'helpdesk1234',
        'servicedesk1', 'servicedesk123', 'football1234', 'baseball1234', 'sunshine1234',
        'princess1234', 'monkey123456', 'dragon123456', 'trustno11234', 'superman1234',
        'asdfghjkl123', 'zxcvbnm12345', 'qazwsxedcrfv', 'password2024', 'password2025',
        'password2026', 'january12345', 'philippines1', 'philippines123', 'manila123456',
    ];

    public static function rulesText(): string
    {
        return 'At least ' . self::MIN_LENGTH . ' characters. Not your email, not a common password, and not your current one.';
    }

    // returns an error message, or null when the password is fine
    public static function check(string $password, string $confirm, string $email, ?string $currentHash = null): ?string
    {
        $lower = mb_strtolower($password);
        $mail  = mb_strtolower(trim($email));
        $local = strstr($mail, '@', true) ?: $mail;

        return match (true) {
            $password === ''
                => 'Enter a new password.',
            mb_strlen($password) < self::MIN_LENGTH
                => 'Use at least ' . self::MIN_LENGTH . ' characters.',
            $password !== $confirm
                => 'The two new passwords do not match.',
            $mail !== '' && ($lower === $mail || $lower === $local)
                => 'Do not use your email as your password.',
            in_array($lower, self::COMMON, true) || self::isRepeat($lower)
                => 'That password is too common. Pick something harder to guess.',
            $currentHash !== null && $currentHash !== '' && password_verify($password, $currentHash)
                => 'Pick a password different from your current one.',
            default => null,
        };
    }

    // catches one character typed over and over
    private static function isRepeat(string $value): bool
    {
        return $value !== '' && count(array_unique(mb_str_split($value))) <= 2;
    }
}
