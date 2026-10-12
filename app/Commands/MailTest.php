<?php

namespace App\Commands;

use App\Libraries\Mailer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Email as EmailConfig;

class MailTest extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:mailtest';
    protected $description = 'Sends one test email through the configured SMTP relay and prints the conversation.';
    protected $usage       = 'helpdesk:mailtest <address>';

    public function run(array $params)
    {
        $to = $params[0] ?? CLI::prompt('Send a test message to');

        $config = config(EmailConfig::class);

        CLI::write(sprintf(
            'relay  %s:%d  crypto=%s  user=%s  pass=%d chars  from=%s',
            $config->SMTPHost,
            $config->SMTPPort,
            $config->SMTPCrypto ?: '(none)',
            $config->SMTPUser ?: '(none)',
            strlen($config->SMTPPass),
            $config->fromEmail
        ), 'yellow');
        CLI::newLine();

        $sent = (new Mailer())->send(
            $to,
            'Chikiting System test message',
            "If you are reading this, mail delivery works.\n"
        );

        if ($sent) {
            CLI::write('SENT - the relay accepted it. Check the inbox (and junk).', 'green');

            return;
        }

        CLI::write('FAILED - the relay refused it. See writable/logs for the SMTP conversation.', 'red');
    }
}
