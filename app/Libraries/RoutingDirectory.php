<?php

namespace App\Libraries;

use App\Models\DepartmentModel;
use App\Models\TicketModel;

/**
 * Who handles what - for checking that a ticket landed with the right team.
 *
 * The model is never shown the whole organisation. The app scores every active
 * department and role against the request's own words (no AI, no tokens),
 * keeps the requester's pick plus the few best alternatives, and the model only
 * has to say which of those numbered options owns the request. The app then
 * checks that answer and makes the move itself.
 */
class RoutingDirectory
{
    public const LIMIT = 4;

    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'have', 'has', 'had', 'from', 'you', 'your', 'our', 'are',
        'was', 'were', 'can', 'could', 'would', 'should', 'need', 'want', 'please', 'help', 'thanks', 'thank',
        'hello', 'just', 'also', 'but', 'not', 'all', 'any', 'get', 'got', 'how', 'what', 'when', 'where', 'who',
        'why', 'which', 'there', 'their', 'them', 'they', 'she', 'him', 'her', 'its', 'into', 'about', 'been',
        'will', 'did', 'does', 'done', 'make', 'made', 'still', 'some', 'very', 'really', 'today', 'yesterday',
        'tomorrow', 'since', 'last', 'morning', 'week', 'month', 'able', 'unable', 'cannot', 'cant', 'dont',
        'issue', 'problem', 'request', 'ticket', 'team', 'like', 'know', 'one', 'out', 'way', 'time',
    ];

    public function entries(): array
    {
        $entries = [];

        foreach ((new DepartmentModel())->withRoles() as $department) {
            if ((int) $department['is_active'] !== 1) {
                continue;
            }

            $base = [
                'department'         => (string) $department['name'],
                'department_handles' => trim((string) ($department['handles'] ?? '')),
            ];

            $entries[] = $base + ['role' => '', 'handles' => ''];

            foreach ($department['roles'] as $role) {
                $entries[] = $base + ['role' => (string) $role['name'], 'handles' => trim((string) ($role['handles'] ?? ''))];
            }
        }

        return $entries;
    }

    public function shortlist(string $text, string $department, string $role): array
    {
        return self::rank($this->entries(), $text, $department, $role);
    }

    /**
     * The requester's pick first, then up to LIMIT - 1 alternatives that share
     * the most (and the rarest) words with the request. Pure, so it is tested
     * without a database.
     */
    public static function rank(array $entries, string $text, string $department, string $role, int $limit = self::LIMIT): array
    {
        $chosen = null;

        foreach ($entries as $entry) {
            if (self::same($entry, $department, $role)) {
                $chosen = $entry;
                break;
            }
        }

        $chosen ??= ['department' => $department, 'department_handles' => '', 'role' => $role, 'handles' => ''];

        $words = self::words($text);

        if ($words === [] || $limit < 2) {
            return [$chosen];
        }

        $docs = array_map(static fn ($e) => self::words(self::describe($e)), $entries);

        $frequency = [];
        foreach ($words as $word) {
            $frequency[$word] = count(array_filter($docs, static fn ($doc) => self::has($doc, $word)));
        }

        $scored = [];
        foreach ($entries as $i => $entry) {
            if (self::same($entry, $chosen['department'], $chosen['role'])) {
                continue;
            }

            $score = 0.0;
            foreach ($words as $word) {
                if ($frequency[$word] > 0 && self::has($docs[$i], $word)) {
                    $score += log(1 + count($docs) / $frequency[$word]);
                }
            }

            if ($score > 0) {
                $scored[] = [$score, $i];
            }
        }

        usort($scored, static fn ($a, $b) => $b[0] <=> $a[0]);

        $options = [$chosen];
        foreach (array_slice($scored, 0, $limit - 1) as [, $i]) {
            $options[] = $entries[$i];
        }

        return $options;
    }

    /**
     * One small, focused question for the local model - a few hundred tokens,
     * a couple of seconds. The teams are listed alphabetically and the
     * requester's pick is not marked, so the model judges the request rather
     * than agreeing with whoever picked first.
     */
    public function prompt(string $request, array $options): string
    {
        $teams = array_values($options);
        usort($teams, static fn ($a, $b) => strcasecmp(self::label($a), self::label($b)));

        $lines = [];
        foreach ($teams as $team) {
            $what    = self::what($team);
            $lines[] = '- ' . self::label($team) . ($what !== '' ? ': handles ' . $what : '');
        }

        return implode("\n", [
            'Request: ' . trim(mb_substr($request, 0, 600)),
            '',
            'Teams:',
            ...$lines,
            '',
            'Which ONE team handles this request? Use each team\'s "handles" list. Answer with only the team name, exactly as written above.',
        ]);
    }

    /**
     * A move needs two independent signals to agree:
     *
     *  1. the model named one of the teams actually offered, and not the
     *     requester's own pick, and
     *  2. that team's own "handles" wording covers more of the request than
     *     the requester's pick does.
     *
     * Anything less keeps the requester's choice. The model alone can be wrong
     * (a 3B model sent a vacation request to Payroll in testing); the words
     * alone are blunt. Together they only move what plainly belongs elsewhere.
     */
    public function decide(array $options, array $output, string $request): ?array
    {
        $options = array_values($options);

        if (count($options) < 2) {
            return null;
        }

        $from = $options[0];
        $to   = self::named((string) ($output['routed_team'] ?? ''), $options);

        if ($to === null || self::same($to, $from['department'], $from['role'])) {
            return null;
        }

        if (self::evidence($to, $request) <= self::evidence($from, $request)) {
            return null;
        }

        return [
            'department'      => $to['department'],
            'role'            => $to['role'],
            'from'            => self::label($from),
            'to'              => self::label($to),
            'from_department' => $from['department'],
            'from_role'       => $from['role'],
        ];
    }

    public function reroute(string $ticketId, array $options, array $output, string $request): ?array
    {
        $route = $this->decide($options, $output, $request);

        if ($route !== null) {
            (new TicketModel())->applyRoute($ticketId, $route['department'], $route['role'], 'moved by the assistant from ' . $route['from']);

            log_message('info', 'Routing: ticket {id} moved from {from} to {to}', [
                'id' => $ticketId, 'from' => $route['from'], 'to' => $route['to'],
            ]);
        }

        return $route;
    }

    /**
     * The team that owns a ticket now. Until something moves it, that is the
     * requester's own pick; after a move, the department and role it was
     * moved to.
     */
    public static function owner(array $fields): array
    {
        $moved = trim((string) ($fields['Routing Note'] ?? '')) !== '';

        return [
            'department' => (string) ticket_department($fields),
            'role'       => (string) ($moved ? ($fields['Responsible Role'] ?? '') : ($fields['Submitting Role'] ?? '')),
        ];
    }

    public function isTeam(string $department, string $role): bool
    {
        foreach ($this->entries() as $entry) {
            if (self::same($entry, $department, $role)) {
                return true;
            }
        }

        return false;
    }

    public static function label(array $option): string
    {
        return $option['role'] === '' ? $option['department'] : $option['department'] . ' / ' . $option['role'];
    }

    /**
     * Maps the model's free-text answer back to exactly one offered team, by
     * whole words: its full label ("HR / Payroll") or its role name alone
     * ("Payroll"). A role beats its own department, so "Employee Relations
     * (HR)" means the role. Two different teams named means the answer is
     * unclear, and nothing moves.
     */
    public static function named(string $answer, array $options): ?array
    {
        if (trim($answer) === '') {
            return null;
        }

        $hits = [];

        foreach (array_values($options) as $i => $option) {
            foreach (array_filter([self::label($option), $option['role']]) as $name) {
                $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu';

                if (preg_match($pattern, $answer) === 1) {
                    $hits[$i] = $option;
                }
            }
        }

        $hits = array_filter($hits, static function ($hit) use ($hits) {
            if ($hit['role'] !== '') {
                return true;
            }

            foreach ($hits as $other) {
                if ($other['role'] !== '' && strcasecmp($other['department'], $hit['department']) === 0) {
                    return false;
                }
            }

            return true;
        });

        return count($hits) === 1 ? array_values($hits)[0] : null;
    }

    /** How many of the request's words a team's own description covers. */
    public static function evidence(array $option, string $request): int
    {
        $own   = $option['role'] === '' ? $option['department'] . ' ' . $option['department_handles'] : $option['role'] . ' ' . self::what($option);
        $doc   = self::words($own);
        $count = 0;

        foreach (self::words($request) as $word) {
            if (self::has($doc, $word)) {
                $count++;
            }
        }

        return $count;
    }

    private static function what(array $option): string
    {
        return $option['role'] === '' ? $option['department_handles'] : ($option['handles'] ?: $option['department_handles']);
    }

    private static function same(array $entry, string $department, string $role): bool
    {
        return strcasecmp($entry['department'], $department) === 0 && strcasecmp($entry['role'], $role) === 0;
    }

    private static function describe(array $entry): string
    {
        return implode(' ', [$entry['department'], $entry['department_handles'], $entry['role'], $entry['handles']]);
    }

    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower(str_replace("'", '', $text)), $m);

        $words = [];
        foreach ($m[0] as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $words[self::stem($word)] = true;
        }

        return array_keys($words);
    }

    private static function stem(string $word): string
    {
        if (mb_strlen($word) > 4 && str_ends_with($word, 'ies')) {
            return mb_substr($word, 0, -3) . 'y';
        }

        if (mb_strlen($word) > 4 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }

    private static function has(array $doc, string $word): bool
    {
        foreach ($doc as $candidate) {
            if ($candidate === $word) {
                return true;
            }

            $short = mb_strlen($candidate) < mb_strlen($word) ? $candidate : $word;
            $long  = $short === $candidate ? $word : $candidate;

            if (mb_strlen($short) >= 4 && str_starts_with($long, $short)) {
                return true;
            }
        }

        return false;
    }
}
