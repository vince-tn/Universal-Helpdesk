<?php

namespace App\Libraries;

// account security emails, these ignore TICKET_EMAILS and the notify setting
final class SecurityNotice
{
    public static function passwordChanged(array $user, string $how): bool
    {
        $to = trim((string) ($user['email'] ?? ''));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL) || str_ends_with($to, '.local')) {
            return false;
        }

        $mailer = new Mailer();

        if (! $mailer->isConfigured()) {
            return false;
        }

        $name = trim((string) ($user['name'] ?? ''));
        $body = implode("\n", [
            'Hi ' . ($name === '' ? 'there' : strtok($name, ' ')) . ',',
            '',
            'Your ' . brand_name() . ' password was changed on ' . date('j M Y \a\t g:i a') . ' (' . $how . ').',
            '',
            'If this was you, there is nothing to do.',
            'If it was not you, reset your password now from the login page and tell IT.',
        ]);

        try {
            return $mailer->send($to, 'Your ' . brand_name() . ' password was changed', $body);
        } catch (\Throwable $e) {
            log_message('error', 'password change notice failed: {m}', ['m' => $e->getMessage()]);

            return false;
        }
    }
}
