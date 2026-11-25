<?php

namespace App\Libraries;

use App\Models\TicketMetaModel;

// sla targets, due dates and the pause clock
class Sla
{
    public const PAUSING = ['Awaiting Requester', 'On Hold', 'Pending Approval'];

    public const DONE = ['Resolved', 'Closed', 'Cancelled'];

    private const RISK_SHARE = 0.8;

    public function policy(string $category, string $priority): array
    {
        $db  = \Config\Database::connect();
        $row = $db->table('sla_policies')->where(['category' => $category, 'priority' => $priority])->get()->getRowArray()
            ?? $db->table('sla_policies')->where(['category' => 'Common Request', 'priority' => $priority ?: 'Medium'])->get()->getRowArray()
            ?? ['response_minutes' => 240, 'resolution_minutes' => 1440];

        return [(int) $row['response_minutes'], (int) $row['resolution_minutes']];
    }

    // sets both targets from the ticket's start plus any paused time
    public function stamp(string $ticketId, string $createdAt, string $category, string $priority): void
    {
        $meta = (new TicketMetaModel())->firstOrCreate($ticketId);

        [$response, $resolution] = $this->policy($category ?: 'Common Request', $priority ?: 'Medium');

        $start  = strtotime($createdAt) ?: time();
        $paused = (int) ($meta['sla_paused_minutes'] ?? 0);

        (new TicketMetaModel())->updateForTicket($ticketId, [
            'response_due_at' => date('Y-m-d H:i:s', $start + $response * 60),
            'due_at'          => date('Y-m-d H:i:s', $start + ($resolution + $paused) * 60),
            'due_date'        => date('Y-m-d', $start + ($resolution + $paused) * 60),
        ]);
    }

    public function onStatusChange(string $ticketId, string $from, string $to): void
    {
        $model = new TicketMetaModel();
        $meta  = $model->firstOrCreate($ticketId);
        $now   = date('Y-m-d H:i:s');
        $data  = [];

        $wasPaused = ! empty($meta['paused_at']);
        $pauseNow  = in_array($to, self::PAUSING, true);

        if ($wasPaused && ! $pauseNow) {
            $minutes = max(0, intdiv(time() - strtotime($meta['paused_at']), 60));
            $data['sla_paused_minutes'] = (int) ($meta['sla_paused_minutes'] ?? 0) + $minutes;
            $data['paused_at']          = null;

            if (! empty($meta['due_at'])) {
                $due = strtotime($meta['due_at']) + $minutes * 60;
                $data['due_at']   = date('Y-m-d H:i:s', $due);
                $data['due_date'] = date('Y-m-d', $due);
            }
        } elseif (! $wasPaused && $pauseNow) {
            $data['paused_at'] = $now;
        }

        if ($to === 'Resolved') {
            $data['resolved_at'] = $now;
        }

        if ($to === 'Reopened' || (in_array($from, ['Resolved', 'Closed'], true) && ! in_array($to, self::DONE, true))) {
            $data['reopened_count'] = (int) ($meta['reopened_count'] ?? 0) + 1;
            $data['resolved_at']    = null;
            $data['sla_warned_at']  = null;
        }

        if ($data !== []) {
            $model->updateForTicket($ticketId, $data);
        }
    }

    public function markFirstResponse(string $ticketId): void
    {
        $model = new TicketMetaModel();
        $meta  = $model->firstOrCreate($ticketId);

        if (empty($meta['first_response_at'])) {
            $model->updateForTicket($ticketId, ['first_response_at' => date('Y-m-d H:i:s')]);
        }
    }

    // ok, at_risk, breached, met, paused or none
    public function state(array $meta, string $status, ?string $createdAt = null): string
    {
        if (empty($meta['due_at'])) {
            return 'none';
        }

        $due = strtotime($meta['due_at']);

        if (in_array($status, self::DONE, true)) {
            $end = strtotime((string) ($meta['resolved_at'] ?? '')) ?: time();

            return $end <= $due ? 'met' : 'breached';
        }

        if (! empty($meta['paused_at'])) {
            return 'paused';
        }

        if (time() > $due) {
            return 'breached';
        }

        $start = strtotime((string) $createdAt) ?: ($due - 86400);
        $span  = max(60, $due - $start);

        return (time() - $start) / $span >= self::RISK_SHARE ? 'at_risk' : 'ok';
    }

    public static function label(string $state): string
    {
        return match ($state) {
            'ok'       => 'On track',
            'at_risk'  => 'At risk',
            'breached' => 'Breached',
            'met'      => 'Met',
            'paused'   => 'Paused',
            default    => 'No SLA',
        };
    }

    // human length like 4 h or 3 d
    public static function minutesLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        if ($minutes < 1440 || $minutes % 1440 !== 0) {
            return rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.') . ' h';
        }

        return intdiv($minutes, 1440) . ' d';
    }
}
