<?php

namespace App\Commands;

use App\Controllers\TicketController;
use App\Libraries\Activity;
use App\Libraries\GraphMail;
use App\Libraries\Notifier;
use App\Libraries\Settings;
use App\Libraries\WeeklySummary;
use App\Libraries\Sla;
use App\Models\TicketMetaModel;
use App\Models\TicketModel;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

// sla warnings, auto close of resolved tickets, email intake and the mail queue, run every few minutes
class Housekeeping extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'tickets:housekeeping';
    protected $description = 'Warns on SLAs at risk, closes stale resolved tickets and sends queued mail.';

    public function run(array $params)
    {
        $tickets = new TicketModel();
        $metas   = new TicketMetaModel();
        $sla     = new Sla();
        $days    = max(1, (int) (env('AUTO_CLOSE_DAYS') ?: 5));
        $warned  = 0;
        $closed  = 0;

        foreach ($tickets->whereIn('status', TicketModel::OPEN_STATUSES)->findAll() as $row) {
            $id   = (string) $row['id'];
            $meta = $metas->firstOrCreate($id);

            if (! empty($meta['sla_warned_at'])) {
                continue;
            }

            $state = $sla->state($meta, (string) $row['status'], $row['created_at']);

            if (in_array($state, ['at_risk', 'breached'], true)) {
                $fields = $tickets->getTicket($id)['fields'] ?? [];
                (new Notifier())->slaAtRisk($id, $fields, $state);
                $metas->updateForTicket($id, ['sla_warned_at' => date('Y-m-d H:i:s')]);
                Activity::ticket($id, 'sla', null, $state === 'breached' ? 'SLA breached' : 'SLA at risk', ['id' => null, 'name' => 'System']);
                $warned++;
            }
        }

        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

        foreach ($tickets->where('status', 'Resolved')->findAll() as $row) {
            $id   = (string) $row['id'];
            $meta = $metas->firstOrCreate($id);
            $when = $meta['resolved_at'] ?? $row['updated_at'];

            if ($when !== null && $when < $cutoff) {
                $fields = $tickets->getTicket($id)['fields'] ?? [];
                $tickets->updateStatus($id, 'Closed');
                $sla->onStatusChange($id, 'Resolved', 'Closed');
                Activity::ticket($id, 'status', 'Resolved', 'Closed', ['id' => null, 'name' => 'System']);
                (new TicketController())->sendSurvey($id, $fields);
                $closed++;
            }
        }

        if (GraphMail::configured()) {
            try {
                $this->call('tickets:mail-intake');
            } catch (\Throwable $e) {
                CLI::error('mail intake failed: ' . $e->getMessage());
            }
        }

        // temporary accounts are switched off once their end date passes
        $expired = (new UserModel())->where('is_active', 1)->where('expires_at IS NOT NULL', null, false)->where('expires_at <=', date('Y-m-d H:i:s'))->findAll();
        foreach ($expired as $u) {
            (new UserModel())->update((int) $u['id'], ['is_active' => 0]);
            Activity::audit('user_expired', 'disabled ' . $u['email'] . ' at its end date', null, ['id' => null, 'name' => 'System']);
        }

        // monday 8 am manila summary, once per week
        $manila = new \DateTimeImmutable('now', new \DateTimeZone(WeeklySummary::TZ));
        if (WeeklySummary::isDue($manila, Settings::get(WeeklySummary::SETTING))) {
            try {
                Settings::set(WeeklySummary::SETTING, $manila->format('Y-m-d H:i:s'));
                $this->call('tickets:weekly-summary');
            } catch (\Throwable $e) {
                CLI::error('weekly summary failed: ' . $e->getMessage());
            }
        }

        $sent = Notifier::flush((int) (env('MAIL_BATCH') ?: 30));

        if ($warned + $closed + $sent > 0 || CLI::getOption('verbose')) {
            CLI::write(date('Y-m-d H:i') . " housekeeping: {$warned} sla warning(s), {$closed} auto-closed, {$sent} mail(s) sent");
        }
    }
}
