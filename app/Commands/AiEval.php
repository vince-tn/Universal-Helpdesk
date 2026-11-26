<?php

namespace App\Commands;

use App\Libraries\Guardrails;
use App\Libraries\PriorCases;
use App\Libraries\RoutingDirectory;
use App\Libraries\TicketAssistant;
use App\Models\DepartmentModel;
use App\Models\N8nService;
use App\Models\TicketModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\N8n;

class AiEval extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:ai-eval';
    protected $description = 'Runs the sample requests through the live classifier and chat, grades routing and replies, and writes a CSV and a summary.';
    protected $usage       = 'helpdesk:ai-eval [--department <name>] [--limit <n>] [--local-only] [--chat] [--delay <seconds>] [--out <folder>]';
    protected $options     = [
        '--department' => 'Only cases for this department (expected department).',
        '--limit'      => 'At most this many cases per department.',
        '--local-only' => 'Send every case to the local model only.',
        '--chat'       => 'Also run the follow-up chat test for each case.',
        '--delay'      => 'Seconds to wait between AI calls (default 3).',
        '--out'        => 'Report folder (default writable/reports).',
    ];

    private const CASES_FILE = APPPATH . 'Data/ai_eval_cases.json';

    private const MAX_REPLY = 1200;

    private const PLACEHOLDER = '/to be confirmed|not provided|\bTBD\b|\bTBC\b|\bN\/A\b|lorem ipsum|placeholder|\[(?:name|date|insert|your|agent|team)[^\]]*\]|\{\{|<[a-z_ ]+>/i';

    private const PROMISE = [
        '/\b(?:is|are|has been|have been|was|were|will be|is now)\s+(?:now\s+)?approved\b/i',
        '/\bwe (?:will|can|have) (?:approve|refund|reimburse|credit|pay)\b/i',
        '/\byou will (?:receive|get|be paid|be refunded|be credited)\b/i',
        '/\bwill be (?:refunded|credited|reimbursed|paid out)\b/i',
        '/(?:₱|\bphp|\bpeso|\$|\baud)\s?\d/i',
        '/\bguarantee/i',
    ];

    private const CONDITIONAL = '/\b(?:once|if|after|until|unless|need|needs|must|pending|require|requires|required|subject to|whether|cannot|can\'t|not able|no one can)\b/i';

    private float $delay = 3.0;
    private int $calls = 0;
    private array $confidentialTeams = [];

    public function run(array $params)
    {
        $only      = trim((string) $this->opt($params, 'department', ''));
        $limit     = (int) $this->opt($params, 'limit', 0);
        $localOnly = $this->flag($params, 'local-only');
        $withChat  = $this->flag($params, 'chat');
        $this->delay = max(0.0, (float) $this->opt($params, 'delay', 3));
        $out       = (string) $this->opt($params, 'out', 'writable/reports');

        $cases = $this->load($only, $limit);

        if ($cases === []) {
            CLI::error('No cases to run. Check --department against the names in app/Data/ai_eval_cases.json.');

            return EXIT_ERROR;
        }

        $folder = $this->folder($out);
        if ($folder === null) {
            CLI::error('Cannot create the report folder ' . $out);

            return EXIT_ERROR;
        }

        $this->confidentialTeams = $this->confidentialTeams();

        CLI::write(sprintf('%d case(s)%s%s, %ss between calls.', count($cases), $localOnly ? ', local only' : '', $withChat ? ', with chat' : '', $this->delay), 'yellow');
        CLI::newLine();

        $n8n     = new N8nService();
        $routing = new RoutingDirectory();
        $tickets = new TicketModel();
        $prior   = new PriorCases();
        $guard   = new Guardrails();
        $config  = new N8n();
        $results = [];

        foreach ($cases as $i => $case) {
            CLI::write(sprintf('[%d/%d] %s  %s', $i + 1, count($cases), $case['id'], CLI::color($this->excerpt($case['text']), 'dark_gray')));

            $row       = $this->evaluate($case, $n8n, $routing, $tickets, $prior, $guard, $config, $localOnly, $withChat);
            $results[] = $row;

            $marks = [
                $row['routing_ok'] ? 'route ok' : 'route FAIL',
                $row['reply_ok'] ? 'reply ok' : 'reply FAIL',
            ];
            if ($withChat) {
                $marks[] = $row['chat_ok'] ? 'chat ok' : 'chat FAIL';
            }
            CLI::write('     ' . implode(' | ', $marks) . ' | ' . $row['ai_source'] . ' ' . $row['latency_ms'] . ' ms', $row['passed'] ? 'green' : 'red');
        }

        $stamp = date('Ymd-Hi');
        $csv   = $folder . DIRECTORY_SEPARATOR . 'ai-eval-' . $stamp . '.csv';
        $md    = $folder . DIRECTORY_SEPARATOR . 'ai-eval-' . $stamp . '.md';

        $this->writeCsv($csv, $results);
        [$headers, $table] = $this->summaryTable($results, $withChat);
        file_put_contents($md, $this->markdown($results, $headers, $table, $withChat, $localOnly));

        CLI::newLine();
        CLI::table($table, $headers);
        CLI::newLine();
        CLI::write('CSV:     ' . $csv, 'green');
        CLI::write('Summary: ' . $md, 'green');

        return EXIT_SUCCESS;
    }

    private function evaluate(array $case, N8nService $n8n, RoutingDirectory $routing, TicketModel $tickets, PriorCases $prior, Guardrails $guard, N8n $config, bool $forceLocal, bool $withChat): array
    {
        $text  = (string) $case['text'];
        $local = $forceLocal || ! empty($case['confidential']) || $this->isConfidential($case['picked_department'], $case['picked_role']);

        // same inputs the ticket page sends
        $similar   = $tickets->similarResolved($text, null);
        $context   = $prior->context($similar);
        $submission = [
            'Requester Name'        => 'Eval Tester',
            'Requester Email'       => 'ai-eval@example.invalid',
            'Submitting Department' => (string) $case['picked_department'],
            'Submitting Role'       => (string) $case['picked_role'],
            'Request'               => $text,
        ];

        [$result, $ms] = $this->timed(static fn () => $n8n->classify($submission, $context, $local));

        $row = [
            'id'                  => $case['id'],
            'expected_department' => $case['expected_department'],
            'expected_role'       => $case['expected_role'],
            'picked_department'   => $case['picked_department'],
            'picked_role'         => $case['picked_role'],
            'reroute_case'        => $this->isReroute($case),
            'confidential'        => ! empty($case['confidential']),
            'local_only'          => $local,
            'text'                => $text,
            'final_department'    => $case['picked_department'],
            'final_role'          => $case['picked_role'],
            'model_team'          => '',
            'model_department'    => '',
            'moved'               => false,
            'routing_ok'          => false,
            'routing_reason'      => '',
            'title'               => '',
            'category'            => '',
            'title_ok'            => false,
            'title_reason'        => '',
            'reply'               => '',
            'reply_ok'            => false,
            'reply_reasons'       => [],
            'ai_source'           => 'none',
            'source_ok'           => false,
            'source_reason'       => '',
            'latency_ms'          => $ms,
            'chat_reply'          => '',
            'chat_ok'             => null,
            'chat_reasons'        => [],
            'chat_source'         => '',
            'chat_latency_ms'     => 0,
            'passed'              => false,
        ];

        if ($result === null) {
            $row['routing_reason'] = 'no answer from the classifier after a retry';
            $row['reply_reasons']  = ['no answer from the classifier'];
            $row['title_reason']   = 'no answer';
            $row['source_reason']  = 'no answer';
            if ($withChat) {
                $row['chat_ok']      = false;
                $row['chat_reasons'] = ['skipped, classify failed'];
            }

            return $row;
        }

        $output = $result['output'] ?? [];
        $route  = $routing->decide($result['routing'] ?? [], $output, $text);

        $row['model_team']       = (string) ($output['routed_team'] ?? '');
        $row['model_department'] = (string) ($output['responsible_department'] ?? '');

        if ($route !== null) {
            $row['final_department'] = $route['department'];
            $row['final_role']       = $route['role'];
            $row['moved']            = true;
        }

        $row['routing_ok'] = $this->sameTeam($row['final_department'], $row['final_role'], $case['expected_department'], $case['expected_role']);
        if (! $row['routing_ok']) {
            $row['routing_reason'] = sprintf(
                'expected %s, ended at %s (model said "%s", %d team(s) offered)',
                $this->label($case['expected_department'], $case['expected_role']),
                $this->label($row['final_department'], $row['final_role']),
                $row['model_team'],
                count($result['routing'] ?? [])
            );
        }

        $row['title']    = trim((string) ($output['request_title'] ?? ''));
        $row['category'] = trim((string) ($output['category'] ?? ''));
        $row['title_reason'] = $this->titleProblem($row['title'], $row['category']);
        $row['title_ok']     = $row['title_reason'] === '';

        $row['reply']         = TicketAssistant::clean((string) ($output['requester_message'] ?? ''));
        $row['reply_reasons'] = $this->replyProblems($row['reply'], (array) ($case['must_not'] ?? []), $guard, $row['final_department']);
        $row['reply_ok']      = $row['reply_reasons'] === [];

        $row['ai_source'] = (string) ($result['ai_source'] ?? 'Local');
        $row['source_reason'] = $this->sourceProblem($row['ai_source'], $local, $config);
        $row['source_ok']     = $row['source_reason'] === '';

        if ($withChat) {
            $payload = $this->chatPayload($case, $output, $row, $context, $local);

            [$chat, $chatMs] = $this->timed(static fn () => $n8n->chat($payload));

            $row['chat_latency_ms'] = $chatMs;

            if ($chat === null) {
                $row['chat_ok']      = false;
                $row['chat_reasons'] = ['no answer from chat after a retry'];
            } else {
                $row['chat_reply']   = TicketAssistant::clean((string) $chat['reply']);
                $row['chat_source']  = (string) $chat['ai_source'];
                $row['chat_reasons'] = $this->replyProblems($row['chat_reply'], (array) ($case['must_not'] ?? []), $guard, $row['final_department']);
                $leak                = $this->sourceProblem($row['chat_source'], $local, $config);
                if ($leak !== '') {
                    $row['chat_reasons'][] = $leak;
                }
                $row['chat_ok'] = $row['chat_reasons'] === [];
            }
        }

        $row['passed'] = $row['routing_ok'] && $row['reply_ok'] && $row['title_ok'] && $row['source_ok'] && ($row['chat_ok'] ?? true);

        return $row;
    }

    // minimal ticket payload shaped like TicketAssistant::payload
    private function chatPayload(array $case, array $output, array $row, string $context, bool $local): array
    {
        $now = date('Y-m-d H:i:s');

        return [
            'ticket_id' => 'eval-' . $case['id'],
            'ticket'    => [
                'title'                  => $row['title'],
                'status'                 => 'New',
                'category'               => $row['category'],
                'responsible_department' => $row['final_department'],
                'submitting_department'  => (string) $case['picked_department'],
                'requester'              => 'Eval Tester',
                'description'            => mb_substr((string) ($output['description'] ?? ''), 0, 1500),
                'request_body'           => mb_substr((string) $case['text'], 0, 1500),
                'expected_deliverable'   => (string) ($output['expected_deliverable'] ?? ''),
                'requirements_needed'    => (string) ($output['requirements_needed'] ?? ''),
                'closure_criteria'       => (string) ($output['closure_criteria'] ?? ''),
                'suggested_tat'          => (string) ($output['suggested_tat'] ?? ''),
                'priority'               => 'Medium',
            ],
            'sender_name'         => 'Eval Tester',
            'sender_role'         => 'requester',
            'sender_is_requester' => 'Yes',
            'message'             => (string) ($case['follow_up'] ?? ''),
            'history'             => array_values(array_filter([
                ['author' => 'Eval Tester', 'role' => 'requester', 'body' => mb_substr((string) $case['text'], 0, 600), 'at' => $now],
                $row['reply'] === '' ? null : ['author' => TicketAssistant::AUTHOR, 'role' => TicketAssistant::ROLE, 'body' => mb_substr($row['reply'], 0, 600), 'at' => $now],
            ])),
            'prior_context'       => $context,
            'local_only'          => $local,
        ];
    }

    private function replyProblems(string $reply, array $mustNot, Guardrails $guard, string $department): array
    {
        $problems = [];

        if ($reply === '') {
            return ['empty reply'];
        }

        if (preg_match('/\barticle\s*#?\d+/i', $reply) === 1) {
            $problems[] = 'mentions an internal article number';
        }

        if (preg_match(self::PLACEHOLDER, $reply, $m) === 1) {
            $problems[] = 'placeholder text "' . $m[0] . '"';
        }

        if (TicketAssistant::soundsLikeRequester($reply)) {
            $problems[] = 'written in the requester\'s voice';
        }

        if (mb_strlen($reply) >= self::MAX_REPLY) {
            $problems[] = 'too long (' . mb_strlen($reply) . ' chars)';
        }

        foreach ($this->sentences($reply) as $sentence) {
            if (preg_match(self::CONDITIONAL, $sentence) === 1) {
                continue;
            }

            foreach ($mustNot as $phrase) {
                $phrase = trim((string) $phrase);
                if ($phrase !== '' && mb_stripos($sentence, $phrase) !== false) {
                    $problems[] = 'says "' . $phrase . '"';
                }
            }

            foreach (self::PROMISE as $pattern) {
                if (preg_match($pattern, $sentence, $m) === 1) {
                    $problems[] = 'promises approval or money ("' . trim($m[0]) . '")';
                }
            }
        }

        $blocked = $guard->blockedPhrase($department, $reply);
        if ($blocked !== null) {
            $problems[] = 'guardrail blocked phrase "' . $blocked . '"';
        }

        return array_values(array_unique($problems));
    }

    private function titleProblem(string $title, string $category): string
    {
        if ($title === '') {
            return 'no title';
        }

        if ($category !== '' && strcasecmp($title, $category) === 0) {
            return 'title is just the category';
        }

        $words = count(preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY));

        if ($words < 3 || $words > 12) {
            return 'title has ' . $words . ' word(s), wants 3 to 12';
        }

        return '';
    }

    private function sourceProblem(string $source, bool $local, N8n $config): string
    {
        if (! in_array($source, ['OpenRouter', 'Gemini', 'Local'], true)) {
            return 'unknown ai_source "' . $source . '"';
        }

        if ($local && $config->confidentialLocalOnly() && $source !== 'Local') {
            return 'local-only case was answered by ' . $source;
        }

        return '';
    }

    // retries once on null and records the latency
    private function timed(callable $call): array
    {
        $result = null;
        $ms     = 0;

        for ($try = 0; $try < 2 && $result === null; $try++) {
            $this->pause();
            $start  = hrtime(true);
            $result = $call();
            $ms     = (int) round((hrtime(true) - $start) / 1e6);
        }

        return [$result, $ms];
    }

    private function pause(): void
    {
        if ($this->calls++ > 0 && $this->delay > 0) {
            usleep((int) ($this->delay * 1e6));
        }
    }

    private function summaryTable(array $results, bool $withChat): array
    {
        $headers = ['Department', 'Routed', 'Re-route fixed', 'Replies', 'Titles'];
        if ($withChat) {
            $headers[] = 'Chat';
        }
        array_push($headers, 'Avg ms', 'Cloud/Local');

        $groups = [];
        foreach ($results as $row) {
            $groups[$row['expected_department']][] = $row;
        }
        $groups['All'] = $results;

        $table = [];
        foreach ($groups as $department => $rows) {
            $n       = count($rows);
            $reroute = array_filter($rows, static fn ($r) => $r['reroute_case']);
            $cloud   = count(array_filter($rows, static fn ($r) => in_array($r['ai_source'], ['OpenRouter', 'Gemini'], true)));
            $localN  = count(array_filter($rows, static fn ($r) => $r['ai_source'] === 'Local'));
            $times   = array_column(array_filter($rows, static fn ($r) => $r['ai_source'] !== 'none'), 'latency_ms');

            $line = [
                (string) $department,
                $this->ratio(count(array_filter($rows, static fn ($r) => $r['routing_ok'])), $n),
                $this->ratio(count(array_filter($reroute, static fn ($r) => $r['routing_ok'])), count($reroute)),
                $this->ratio(count(array_filter($rows, static fn ($r) => $r['reply_ok'])), $n),
                $this->ratio(count(array_filter($rows, static fn ($r) => $r['title_ok'])), $n),
            ];
            if ($withChat) {
                $line[] = $this->ratio(count(array_filter($rows, static fn ($r) => $r['chat_ok'] === true)), $n);
            }
            $line[] = $times === [] ? '-' : (string) (int) round(array_sum($times) / count($times));
            $line[] = $cloud . '/' . $localN;

            $table[] = $line;
        }

        return [$headers, $table];
    }

    private function markdown(array $results, array $headers, array $table, bool $withChat, bool $localOnly): string
    {
        $lines = [
            '# AI evaluation ' . date('Y-m-d H:i'),
            '',
            sprintf('%d case(s)%s%s. Routing is graded after RoutingDirectory::decide, replies after TicketAssistant::clean. Sentences with a condition (if, once, needs, must) are not counted as promises.', count($results), $localOnly ? ', local model only' : '', $withChat ? ', with the chat follow-up' : ''),
            '',
            '| ' . implode(' | ', $headers) . ' |',
            '|' . str_repeat(' --- |', count($headers)),
        ];

        foreach ($table as $line) {
            $lines[] = '| ' . implode(' | ', array_map(fn ($c) => $this->cell((string) $c), $line)) . ' |';
        }

        $lines[] = '';
        $lines[] = '## Failures';
        $lines[] = '';

        $failures = array_filter($results, static fn ($r) => ! $r['passed']);

        if ($failures === []) {
            $lines[] = 'None.';
        }

        foreach ($failures as $row) {
            $lines[] = '### ' . $row['id'] . ' (' . $row['expected_department'] . ')';
            $lines[] = '';
            $lines[] = '> ' . $this->flat($row['text']);
            $lines[] = '';
            $lines[] = '- Picked: ' . $this->label($row['picked_department'], $row['picked_role']) . ', expected: ' . $this->label($row['expected_department'], $row['expected_role']);
            if (! $row['routing_ok']) {
                $lines[] = '- Routing: ' . $row['routing_reason'];
            }
            if (! $row['title_ok']) {
                $lines[] = '- Title: ' . $row['title_reason'] . ($row['title'] !== '' ? ' ("' . $this->flat($row['title']) . '")' : '');
            }
            if (! $row['reply_ok']) {
                $lines[] = '- Reply: ' . implode('; ', $row['reply_reasons']);
                if ($row['reply'] !== '') {
                    $lines[] = '  - Text: ' . $this->flat(mb_substr($row['reply'], 0, 400));
                }
            }
            if (! $row['source_ok']) {
                $lines[] = '- Source: ' . $row['source_reason'];
            }
            if ($row['chat_ok'] === false) {
                $lines[] = '- Chat: ' . implode('; ', $row['chat_reasons']);
                if ($row['chat_reply'] !== '') {
                    $lines[] = '  - Text: ' . $this->flat(mb_substr($row['chat_reply'], 0, 400));
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function writeCsv(string $path, array $results): void
    {
        $columns = [
            'id', 'expected_department', 'expected_role', 'picked_department', 'picked_role', 'reroute_case', 'confidential',
            'local_only', 'final_department', 'final_role', 'moved', 'model_team', 'model_department', 'routing_ok', 'routing_reason',
            'title', 'category', 'title_ok', 'title_reason', 'reply_ok', 'reply_reasons', 'ai_source', 'source_ok', 'source_reason',
            'latency_ms', 'chat_ok', 'chat_reasons', 'chat_source', 'chat_latency_ms', 'passed', 'text', 'reply', 'chat_reply',
        ];

        $fh = fopen($path, 'wb');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $columns, ',', '"', '');

        foreach ($results as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value  = $row[$column] ?? '';
                $line[] = match (true) {
                    is_array($value) => implode('; ', $value),
                    is_bool($value)  => $value ? 'yes' : 'no',
                    $value === null  => '',
                    default          => (string) $value,
                };
            }
            fputcsv($fh, $line, ',', '"', '');
        }

        fclose($fh);
    }

    private function load(string $only, int $limit): array
    {
        if (! is_file(self::CASES_FILE)) {
            CLI::error('Missing ' . self::CASES_FILE);

            return [];
        }

        $data  = json_decode((string) file_get_contents(self::CASES_FILE), true);
        $cases = is_array($data['cases'] ?? null) ? $data['cases'] : [];
        $count = [];
        $keep  = [];

        foreach ($cases as $case) {
            $department = (string) ($case['expected_department'] ?? '');

            if ($only !== '' && strcasecmp($department, $only) !== 0) {
                continue;
            }

            $count[$department] = ($count[$department] ?? 0) + 1;

            if ($limit > 0 && $count[$department] > $limit) {
                continue;
            }

            $keep[] = $case + ['expected_role' => '', 'picked_department' => $department, 'picked_role' => '', 'confidential' => false, 'must_not' => [], 'follow_up' => ''];
        }

        return $keep;
    }

    private function folder(string $out): ?string
    {
        $path = preg_match('#^([a-zA-Z]:)?[\\\\/]#', $out) === 1 ? $out : ROOTPATH . $out;
        $path = rtrim($path, '\\/');

        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            return null;
        }

        return $path;
    }

    private function confidentialTeams(): array
    {
        $teams = [];

        try {
            foreach ((new DepartmentModel())->withRoles() as $department) {
                if ((int) ($department['is_confidential'] ?? 0) === 1) {
                    $teams[] = strtolower($department['name']) . '|';
                }
                foreach ($department['roles'] as $role) {
                    if ((int) ($role['is_confidential'] ?? 0) === 1) {
                        $teams[] = strtolower($department['name'] . '|' . $role['name']);
                    }
                }
            }
        } catch (\Throwable $e) {
            CLI::write('Could not read confidential teams: ' . $e->getMessage(), 'yellow');
        }

        return $teams;
    }

    private function isConfidential(string $department, string $role): bool
    {
        return in_array(strtolower($department) . '|', $this->confidentialTeams, true)
            || ($role !== '' && in_array(strtolower($department . '|' . $role), $this->confidentialTeams, true));
    }

    private function isReroute(array $case): bool
    {
        return ! $this->sameTeam((string) $case['picked_department'], (string) $case['picked_role'], (string) $case['expected_department'], (string) $case['expected_role']);
    }

    private function sameTeam(string $department, string $role, string $expectedDepartment, string $expectedRole): bool
    {
        if (strcasecmp($department, $expectedDepartment) !== 0) {
            return false;
        }

        return $expectedRole === '' || strcasecmp($role, $expectedRole) === 0;
    }

    private function sentences(string $text): array
    {
        return preg_split('/(?<=[.!?])\s+|\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
    }

    private function label(string $department, string $role): string
    {
        return $role === '' ? $department : $department . ' / ' . $role;
    }

    private function ratio(int $hit, int $of): string
    {
        return $of === 0 ? '-' : $hit . '/' . $of;
    }

    private function flat(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function cell(string $text): string
    {
        return str_replace('|', '\|', $text);
    }

    private function excerpt(string $text): string
    {
        $flat = $this->flat($text);

        return mb_strlen($flat) > 70 ? mb_substr($flat, 0, 70) . '...' : $flat;
    }

    // accepts --name value and --name=value
    private function opt(array $params, string $name, mixed $default): mixed
    {
        $value = $params[$name] ?? CLI::getOption($name);

        if ($value === null || $value === true) {
            foreach (CLI::getOptions() as $key => $raw) {
                if (str_starts_with((string) $key, $name . '=')) {
                    return substr((string) $key, strlen($name) + 1);
                }
            }
        }

        return ($value === null || $value === true || $value === '') ? $default : $value;
    }

    private function flag(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || CLI::getOption($name) !== null;
    }
}
