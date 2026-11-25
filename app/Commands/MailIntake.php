<?php

namespace App\Commands;

use App\Libraries\EmailIntake;
use App\Libraries\GraphMail;
use App\Libraries\MailParsing;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// reads unread mail from the intake mailboxes through microsoft graph
class MailIntake extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'tickets:mail-intake';
    protected $description = 'Turns unread mail in the MAIL_INTAKE mailboxes into tickets and replies.';
    protected $usage       = 'tickets:mail-intake';

    public function run(array $params)
    {
        if (! GraphMail::configured()) {
            CLI::write('Email intake is off. Set GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET and MAIL_INTAKE.', 'yellow');

            return;
        }

        $routes = MailParsing::mailboxes((string) env('MAIL_INTAKE'), departments());

        if ($routes === []) {
            CLI::error('MAIL_INTAKE has no valid mailbox=Department entries.');

            return;
        }

        $counts = (new EmailIntake(GraphMail::fromEnv(), $routes))->run();

        if ($counts !== [] || CLI::getOption('verbose')) {
            $parts = [];
            foreach ($counts as $outcome => $n) {
                $parts[] = $n . ' ' . $outcome;
            }
            CLI::write(date('Y-m-d H:i') . ' mail intake: ' . ($parts === [] ? 'nothing new' : implode(', ', $parts)));
        }
    }
}
