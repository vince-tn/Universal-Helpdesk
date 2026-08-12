<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Email extends BaseConfig
{
    public string $fromEmail  = 'helpdesk@universalhelpdesk.local';
    public string $fromName   = 'Chikiting System';
    public string $recipients = '';

    public string $userAgent = 'CodeIgniter';

    public string $protocol = 'smtp';

    public string $mailPath = '/usr/sbin/sendmail';

    public string $SMTPHost = 'mailpit';

    public string $SMTPAuthMethod = 'login';

    public string $SMTPUser = '';

    public string $SMTPPass = '';

    public int $SMTPPort = 1025;

    public int $SMTPTimeout = 10;

    public bool $SMTPKeepAlive = false;

    public string $SMTPCrypto = 'tls';

    public bool $wordWrap = true;

    public int $wrapChars = 76;

    public string $mailType = 'text';

    public string $charset = 'UTF-8';

    public bool $validate = false;

    public int $priority = 3;

    public string $CRLF = "\r\n";

    public string $newline = "\r\n";

    public bool $BCCBatchMode = false;

    public int $BCCBatchSize = 200;

    public bool $DSN = false;

    public function __construct()
    {
        parent::__construct();

        $this->SMTPHost = (string) (env('MAIL_HOST') ?: $this->SMTPHost);
        $this->SMTPPort = (int) (env('MAIL_PORT') ?: $this->SMTPPort);
        $this->SMTPUser = (string) (env('MAIL_USER') ?: $this->SMTPUser);
        $this->SMTPPass = (string) (env('MAIL_PASS') ?: $this->SMTPPass);
        $this->fromEmail = (string) (env('MAIL_FROM') ?: $this->fromEmail);
        $this->fromName  = (string) (env('MAIL_FROM_NAME') ?: $this->fromName);

        $crypto = strtolower(trim((string) (env('MAIL_CRYPTO') ?: '')));
        $this->SMTPCrypto = in_array($crypto, ['tls', 'ssl'], true) ? $crypto : '';

        if ($this->SMTPUser === '') {
            $this->SMTPPass = '';
        }
    }
}
