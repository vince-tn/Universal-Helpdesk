<?php

namespace App\Controllers;

use App\Libraries\Activity;
use App\Libraries\Sla;
use App\Libraries\SlaTargets;

// sla targets grid and the sign-off record, superadmin only
class AdminSlaController extends BaseController
{
    public function index()
    {
        $policies = $this->policies();
        $last     = $this->db()->table('sla_signoffs')->orderBy('id', 'DESC')->get(1)->getRowArray();
        $changed  = false;

        if ($last !== null) {
            $snapshot = json_decode((string) $last['snapshot'], true);
            $changed  = ! is_array($snapshot) || ! SlaTargets::same($snapshot, $policies);
        }

        $grouped = [];
        foreach ($policies as $p) {
            $grouped[$p['category']][] = $p;
        }

        return view('admin/sla/index', [
            'grouped' => $grouped,
            'last'    => $last,
            'changed' => $changed,
        ]);
    }

    public function save()
    {
        $posted   = (array) ($this->request->getPost('sla') ?? []);
        $policies = $this->policies();
        $updates  = [];
        $errors   = [];

        foreach ($policies as $p) {
            $row = $posted[$p['id']] ?? null;

            if (! is_array($row)) {
                continue;
            }

            $result = SlaTargets::validate((string) ($row['response'] ?? ''), (string) ($row['resolution'] ?? ''));

            if (is_string($result)) {
                $errors[] = $p['category'] . ', ' . $p['priority'] . ': ' . $result;
                continue;
            }

            [$response, $resolution] = $result;

            if ($response !== (int) $p['response_minutes'] || $resolution !== (int) $p['resolution_minutes']) {
                $updates[] = [$p, $response, $resolution];
            }
        }

        if ($errors !== []) {
            $more = count($errors) > 3 ? ' And ' . (count($errors) - 3) . ' more.' : '';

            return redirect()->back()->withInput()->with('error', implode(' ', array_slice($errors, 0, 3)) . $more . ' Nothing was saved.');
        }

        if ($updates === []) {
            return redirect()->to('/admin/sla')->with('success', 'No changes to save.');
        }

        $now = date('Y-m-d H:i:s');

        $this->db()->transStart();
        foreach ($updates as [$p, $response, $resolution]) {
            $this->db()->table('sla_policies')->where('id', $p['id'])->update([
                'response_minutes'   => $response,
                'resolution_minutes' => $resolution,
                'updated_at'         => $now,
            ]);
            Activity::audit('sla_target', sprintf(
                '%s, %s: response %s to %s, resolution %s to %s',
                $p['category'],
                $p['priority'],
                Sla::minutesLabel((int) $p['response_minutes']),
                Sla::minutesLabel($response),
                Sla::minutesLabel((int) $p['resolution_minutes']),
                Sla::minutesLabel($resolution)
            ));
        }
        $this->db()->transComplete();

        if (! $this->db()->transStatus()) {
            return redirect()->back()->withInput()->with('error', 'The targets could not be saved. Please try again.');
        }

        return redirect()->to('/admin/sla')->with('success', count($updates) . ' target(s) saved. New tickets use them from now on.');
    }

    public function signoff()
    {
        $user = current_user();
        $note = trim((string) $this->request->getPost('note'));

        if (mb_strlen($note) > 500) {
            return redirect()->back()->withInput()->with('error', 'Keep the note under 500 characters.');
        }

        $snapshot = SlaTargets::snapshot($this->policies());

        $this->db()->table('sla_signoffs')->insert([
            'user_id'    => $user['id'] ?? null,
            'name'       => mb_substr((string) ($user['name'] ?? 'Unknown'), 0, 150),
            'note'       => $note === '' ? null : $note,
            'snapshot'   => json_encode($snapshot, JSON_PRETTY_PRINT),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Activity::audit('sla_signoff', count($snapshot) . ' targets signed off' . ($note !== '' ? ': ' . $note : ''));

        return redirect()->to('/admin/sla')->with('success', 'Targets signed off.');
    }

    private function policies(): array
    {
        $order = array_flip(ticket_categories());
        $prio  = array_flip(priorities());

        $rows = $this->db()->table('sla_policies')->get()->getResultArray();

        usort($rows, static fn ($a, $b) => [$order[$a['category']] ?? 99, $a['category'], $prio[$a['priority']] ?? 99]
            <=> [$order[$b['category']] ?? 99, $b['category'], $prio[$b['priority']] ?? 99]);

        return $rows;
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return \Config\Database::connect();
    }
}
