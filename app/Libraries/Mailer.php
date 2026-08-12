<?php

namespace App\Libraries;

use Config\Email as EmailConfig;

class Mailer
{
    protected EmailConfig $config;

    public function __construct()
    {
        $this->config = config(EmailConfig::class);
    }

    public function isConfigured(): bool
    {
        return $this->config->protocol === 'smtp' && trim($this->config->SMTPHost) !== '';
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (! $this->isConfigured()) {
            log_message('error', 'Mail not configured - dropping "{subject}" to {to}', [
                'subject' => $subject,
                'to'      => $to,
            ]);

            return false;
        }

        $email = service('email');

        try {
            $email->setFrom($this->config->fromEmail, $this->config->fromName);
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($body);

            if ($email->send(false)) {
                log_message('info', 'Sent "{subject}" to {to}', ['subject' => $subject, 'to' => $to]);

                return true;
            }

            log_message('error', 'Mail to {to} was refused: {debug}', [
                'to'    => $to,
                'debug' => strip_tags($email->printDebugger(['headers'])),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Mail to {to} threw: {message}', [
                'to'      => $to,
                'message' => $e->getMessage(),
            ]);
        } finally {

            $email->clear(true);
        }

        return false;
    }
}
