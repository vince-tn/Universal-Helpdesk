<?php

namespace App\Commands;

use App\Libraries\PriorCases;
use App\Libraries\TicketAssistant;
use App\Models\N8nService;
use App\Models\TicketMetaModel;
use App\Models\TicketModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class Reclassify extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:reclassify';
    protected $description = 'Re-runs the AI classifier over tickets that were saved without one, using the requester\'s original wording.';
    protected $usage       = 'helpdesk:reclassify [--id <n>] [--all] [--dry-run]';
    protected $options     = [
        '--id'      => 'Only this ticket id.',
        '--all'     => 'Every ticket with original wording, not just the flagged ones.',
        '--dry-run' => 'Show what would change without writing anything.',
    ];

    public function run(array $params)
    {
        $only   = $params['id'] ?? CLI::getOption('id');
        $all    = array_key_exists('all', $params) || CLI::getOption('all');
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');

        $tickets   = new TicketModel();
        $meta      = new TicketMetaModel();
        $n8n       = new N8nService();
        $assistant = new TicketAssistant();
        $cases     = new PriorCases();

        $rows = $this->candidates($tickets, $only === null ? null : (int) $only, (bool) $all);

        if ($rows === []) {
            CLI::write('Nothing to do - no ticket is waiting on a classification.', 'green');

            return;
        }

        CLI::write(sprintf(
            '%d ticket(s) to re-classify%s.',
            count($rows),
            $dryRun ? ' (dry run - nothing will be written)' : ''
        ), 'yellow');
        CLI::newLine();

        $done = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id   = (string) $row['id'];
            $body = trim((string) ($row['request_body'] ?? ''));

            CLI::write('#' . $id . '  ' . CLI::color($this->excerpt($body), 'dark_gray'));

            if ($dryRun) {
                continue;
            }

            $prior = $tickets->similarResolved($body, $id);

            $result = $n8n->classify([
                'Requester Name'        => $row['requester'] ?? '',
                'Requester Email'       => $row['requester_email'] ?? '',
                'Submitting Department' => $row['submitting_department'] ?? '',
                'Request'               => $body,
            ], $cases->context($prior));

            $output = $result['output'] ?? [];

            if ($result === null || ($output['model_ok'] ?? 'No') !== 'Yes') {
                CLI::write('     the classifier still is not answering - left alone', 'red');
                CLI::write('     -> php spark helpdesk:doctor', 'dark_gray');
                $failed++;

                continue;
            }

            $tickets->replaceClassification($id, $output, $result['ai_source'] ?? 'Local');

            $meta->updateForTicket($id, [
                'priority' => in_array((string) ($output['priority'] ?? ''), priorities(), true)
                    ? $output['priority'] : 'Medium',
                'due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($output['due_date'] ?? '')) === 1
                    ? $output['due_date'] : null,
            ]);

            $assistant->opening($id, $output, $cases->resolutionFor($prior, $output));

            CLI::write(sprintf(
                '     -> %s | %s | %s',
                $output['request_title'] ?? '(no title)',
                $output['responsible_department'] ?? '(no department)',
                $output['category'] ?? '(no category)'
            ), 'green');

            $done++;
        }

        CLI::newLine();

        if ($dryRun) {
            CLI::write('Dry run - nothing was written.', 'yellow');

            return;
        }

        CLI::write(
            sprintf('%d re-classified, %d left alone.', $done, $failed),
            $failed === 0 ? 'green' : 'yellow'
        );
    }

    private function candidates(TicketModel $tickets, ?int $only, bool $all): array
    {
        $query = $tickets->where('request_body IS NOT NULL')->where('request_body !=', '');

        if ($only !== null) {
            return $query->where('id', $only)->findAll();
        }

        if (! $all) {
            $query = $query->where('needs_human_review', 1);
        }

        return $query->orderBy('id', 'ASC')->findAll();
    }

    private function excerpt(string $body): string
    {
        $flat = preg_replace('/\s+/', ' ', $body) ?? $body;

        return mb_strlen($flat) > 70 ? mb_substr($flat, 0, 70) . '...' : $flat;
    }
}
