<?php

namespace App\Libraries;

use App\Models\SignupAllowlistModel;
use Config\Access;

class SignupPolicy
{
    private const PUBLIC_PROVIDERS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'outlook.com', 'hotmail.com', 'live.com',
        'msn.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.com',
        'zoho.com', 'yandex.com',
    ];

    protected Access $access;

    public function __construct(?Access $access = null)
    {
        $this->access = $access ?? config(Access::class);
    }

    public function allows(string $email): bool
    {
        if ($this->access->allows($email)) {
            return true;
        }

        try {
            $partners = new SignupAllowlistModel();

            return self::matches(
                $email,
                $partners->values(SignupAllowlistModel::KIND_DOMAIN),
                $partners->values(SignupAllowlistModel::KIND_EMAIL)
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function companyDomains(): array
    {
        return $this->access->allowedDomains;
    }

    public static function matches(string $email, array $domains, array $emails): bool
    {
        $email = strtolower(trim($email));
        $at    = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return false;
        }

        if (in_array($email, array_map('strtolower', $emails), true)) {
            return true;
        }

        $domain = substr($email, $at + 1);

        foreach ($domains as $allowed) {
            $allowed = strtolower(trim((string) $allowed));

            if ($allowed !== '' && ($domain === $allowed || str_ends_with($domain, '.' . $allowed))) {
                return true;
            }
        }

        return false;
    }

    public static function parse(string $raw): ?array
    {
        $value = strtolower(trim($raw));
        $value = ltrim($value, '@');

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '@')) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? [SignupAllowlistModel::KIND_EMAIL, $value]
                : null;
        }

        $hostname = '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

        return preg_match($hostname, $value) === 1 ? [SignupAllowlistModel::KIND_DOMAIN, $value] : null;
    }

    public static function isPublicProvider(string $domain): bool
    {
        return in_array(strtolower(trim($domain)), self::PUBLIC_PROVIDERS, true);
    }
}
