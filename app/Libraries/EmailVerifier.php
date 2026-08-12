<?php

namespace App\Libraries;

use App\Models\EmailVerificationModel;
use Config\Access;

class EmailVerifier
{

    public const OK           = 'ok';
    public const NO_CODE      = 'no_code';
    public const EXPIRED      = 'expired';
    public const WRONG        = 'wrong';
    public const TOO_MANY     = 'too_many';

    protected Access $config;
    protected EmailVerificationModel $codes;
    protected Mailer $mailer;

    public function __construct()
    {
        $this->config = config(Access::class);
        $this->codes  = new EmailVerificationModel();
        $this->mailer = new Mailer();
    }

    public function issue(string $email, array $payload = [], string $purpose = 'signup'): array
    {
        $email = strtolower(trim($email));

        $throttle = $this->throttle($email, $purpose);

        if ($throttle !== null) {
            return $throttle;
        }

        $code = $this->mintCode();

        $this->codes->invalidateFor($email, $purpose);

        $id = $this->codes->insert([
            'email'      => $email,
            'purpose'    => $purpose,
            'code_hash'  => password_hash($code, PASSWORD_DEFAULT),
            'payload'    => $payload === [] ? null : json_encode($payload),
            'attempts'   => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + ($this->config->codeTtlMinutes * 60)),
            'ip_address' => $this->callerIp(),
        ], true);

        if ($id === false) {
            log_message('error', 'Could not store a signup code for {email}', ['email' => $email]);

            return [
                'sent'        => false,
                'reason'      => 'We could not start that signup. Please try again.',
                'retry_after' => 0,
            ];
        }

        $sent = $this->mailer->send(
            $email,
            'Your Chikiting System verification code',
            $this->body($code)
        );

        if (! $sent) {

            $this->codes->delete((int) $id);

            if (ENVIRONMENT !== 'production') {
                log_message('warning', 'Mail failed; signup code for {email} was {code}', [
                    'email' => $email,
                    'code'  => $code,
                ]);
            } else {
                log_message('error', 'Could not email a signup code to {email}', ['email' => $email]);
            }

            return [
                'sent'        => false,
                'reason'      => 'We could not send the code just now. Try again in a moment, or ask IT.',
                'retry_after' => 0,
            ];
        }

        $this->codes->prune();

        return ['sent' => true, 'reason' => '', 'retry_after' => 0];
    }

    private function callerIp(): string
    {
        try {
            $request = service('request');

            return method_exists($request, 'getIPAddress')
                ? substr((string) $request->getIPAddress(), 0, 45)
                : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function verify(string $email, string $input, string $purpose = 'signup'): array
    {
        $email = strtolower(trim($email));

        $input = preg_replace('/\D/', '', $input) ?? '';

        $row = $this->codes->activeFor($email, $purpose);

        if ($row === null) {

            return ['status' => self::NO_CODE, 'payload' => [], 'remaining' => 0];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return ['status' => self::EXPIRED, 'payload' => [], 'remaining' => 0];
        }

        $attempts = (int) $row['attempts'] + 1;

        if ($attempts > $this->config->maxAttempts) {
            $this->codes->markConsumed((int) $row['id']);

            return ['status' => self::TOO_MANY, 'payload' => [], 'remaining' => 0];
        }

        if ($input === '' || ! password_verify($input, (string) $row['code_hash'])) {
            $this->codes->recordAttempt((int) $row['id'], $attempts);

            $remaining = max(0, $this->config->maxAttempts - $attempts);

            if ($remaining === 0) {
                $this->codes->markConsumed((int) $row['id']);

                return ['status' => self::TOO_MANY, 'payload' => [], 'remaining' => 0];
            }

            return ['status' => self::WRONG, 'payload' => [], 'remaining' => $remaining];
        }

        $this->codes->markConsumed((int) $row['id']);

        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        return [
            'status'    => self::OK,
            'payload'   => is_array($payload) ? $payload : [],
            'remaining' => 0,
        ];
    }

    public function pendingPayload(string $email, string $purpose = 'signup'): array
    {
        $latest = $this->codes->latestFor($email, $purpose);

        if ($latest === null) {
            return [];
        }

        $payload = json_decode((string) ($latest['payload'] ?? ''), true);

        return is_array($payload) ? $payload : [];
    }

    public function cooldownRemaining(string $email, string $purpose = 'signup'): int
    {
        $latest = $this->codes->latestFor($email, $purpose);

        if ($latest === null || empty($latest['created_at'])) {
            return 0;
        }

        $elapsed = time() - strtotime((string) $latest['created_at']);

        return max(0, $this->config->resendCooldownSeconds - $elapsed);
    }

    public function ttlMinutes(): int
    {
        return $this->config->codeTtlMinutes;
    }

    public function codeLength(): int
    {
        return $this->config->codeLength;
    }

    public function mailIsConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    private function throttle(string $email, string $purpose): ?array
    {
        $wait = $this->cooldownRemaining($email, $purpose);

        if ($wait > 0) {
            return [
                'sent'        => false,
                'reason'      => "Hold on {$wait} more second" . ($wait === 1 ? '' : 's') . ' before asking for another code.',
                'retry_after' => $wait,
            ];
        }

        $recent = $this->codes->sendsSince($email, date('Y-m-d H:i:s', time() - 3600), $purpose);

        if ($recent >= $this->config->maxSendsPerHour) {
            return [
                'sent'        => false,
                'reason'      => 'That address has been sent too many codes in the last hour. Try again later, or ask IT to set the account up.',
                'retry_after' => 3600,
            ];
        }

        return null;
    }

    private function mintCode(): string
    {
        $length = max(4, min(10, $this->config->codeLength));
        $max    = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function body(string $code): string
    {
        $minutes = $this->config->codeTtlMinutes;

        return implode("\n", [
            'Your Chikiting System verification code is:',
            '',
            '    ' . $code,
            '',
            "It is good for {$minutes} minutes and can only be used once.",
            '',
            'If you did not try to create a Chikiting System account, you can ignore this',
            'message - nothing has been created and nobody can finish the signup',
            'without this code.',
        ]);
    }
}
