<?php

namespace App\Commands;

use App\Libraries\Notifier;
use App\Libraries\WeeklySummary as Summary;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// emails the last 7 days summary to team leads, supervisors and super admins
class WeeklySummary extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'tickets:weekly-summary';
    protected $description = 'Emails the weekly ticket summary to each department lead and to super admins.';
    protected $usage       = 'tickets:weekly-summary [--dry-run]';
    protected $options     = ['--dry-run' => 'Print the emails instead of queueing them.'];

    public function run(array $params)
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $mails  = (new Summary())->build(new \DateTimeImmutable('now'));

        if ($dryRun) {
            foreach ($mails as $m) {
                CLI::write('To: ' . $m['user']['email'], 'yellow');
                CLI::write('Subject: ' . $m['subject']);
                CLI::newLine();
                CLI::write($m['body']);
                CLI::write(str_repeat('-', 60));
            }
            CLI::write(count($mails) . ' summary email(s), dry run, nothing queued');

            return;
        }

        $notifier = new Notifier();
        foreach ($mails as $m) {
            $notifier->weeklySummary($m['user'], $m['subject'], $m['body']);
        }

        CLI::write(date('Y-m-d H:i') . ' weekly summary: ' . count($mails) . ' email(s) handed to the queue' . ($notifier->isEnabled() ? '' : ', emails are off so none were queued'));
    }
}
