<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Access extends BaseConfig
{

    public array $allowedDomains = [];

    public array $allowedEmails = [];

    public int $codeLength = 6;

    public int $codeTtlMinutes = 15;

    public int $maxAttempts = 5;

    public int $resendCooldownSeconds = 60;

    public int $maxSendsPerHour = 5;

    public function __construct()
    {
        parent::__construct();

        $this->allowedDomains = $this->list('ALLOWED_EMAIL_DOMAINS', $this->allowedDomains);
        $this->allowedEmails  = $this->list('ALLOWED_EMAILS', $this->allowedEmails);

        $this->codeTtlMinutes        = $this->positive('SIGNUP_CODE_TTL_MINUTES', $this->codeTtlMinutes);
        $this->maxAttempts           = $this->positive('SIGNUP_MAX_ATTEMPTS', $this->maxAttempts);
        $this->resendCooldownSeconds = $this->positive('SIGNUP_RESEND_COOLDOWN', $this->resendCooldownSeconds);
        $this->maxSendsPerHour       = $this->positive('SIGNUP_MAX_SENDS_PER_HOUR', $this->maxSendsPerHour);
    }

    public function domainCheckEnabled(): bool
    {
        return $this->allowedDomains !== [];
    }

    public function allows(string $email): bool
    {
        $email = strtolower(trim($email));

        if (in_array($email, $this->allowedEmails, true)) {
            return true;
        }

        if (! $this->domainCheckEnabled()) {
            return true;
        }

        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = substr($email, $at + 1);

        foreach ($this->allowedDomains as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function domainHint(): string
    {
        if (! $this->domainCheckEnabled()) {
            return '';
        }

        $domains = array_map(static fn ($d) => '@' . $d, $this->allowedDomains);

        if (count($domains) === 1) {
            return $domains[0];
        }

        $last = array_pop($domains);

        return implode(', ', $domains) . ' or ' . $last;
    }

    private function list(string $key, array $default): array
    {
        $raw = (string) (env($key) ?: '');

        if (trim($raw) === '') {
            return $default;
        }

        $items = array_map(

            static fn ($v) => ltrim(strtolower(trim($v)), '@'),
            explode(',', $raw)
        );

        return array_values(array_filter($items, static fn ($v) => $v !== ''));
    }

    private function positive(string $key, int $default): int
    {
        $value = (int) (env($key) ?: 0);

        return $value > 0 ? $value : $default;
    }
}
