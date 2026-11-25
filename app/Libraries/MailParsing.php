<?php

namespace App\Libraries;

// pure helpers for email intake, no database or framework calls
final class MailParsing
{
    public const MAX_BODY = 5000;

    // parses mailbox=department or mailbox=department/role pairs
    public static function mailboxes(string $raw, array $departments = []): array
    {
        $out = [];

        foreach (preg_split('/[,;]/', $raw) ?: [] as $entry) {
            $entry = trim($entry);

            if ($entry === '' || ! str_contains($entry, '=')) {
                continue;
            }

            [$mailbox, $target] = array_map('trim', explode('=', $entry, 2));
            $mailbox = strtolower($mailbox);

            if (filter_var($mailbox, FILTER_VALIDATE_EMAIL) === false || $target === '') {
                continue;
            }

            [$department, $role] = self::splitTarget($target, $departments);

            if ($department === '') {
                continue;
            }

            $out[$mailbox] = ['mailbox' => $mailbox, 'department' => $department, 'role' => $role];
        }

        return array_values($out);
    }

    // department names can hold a slash, like reports/wfm, so known names win
    private static function splitTarget(string $target, array $departments): array
    {
        $best = '';

        foreach ($departments as $name) {
            $name = (string) $name;
            $len  = strlen($name);

            if ($len === 0 || $len <= strlen($best)) {
                continue;
            }

            $head = substr($target, 0, $len);
            $rest = substr($target, $len);

            if (strcasecmp($head, $name) === 0 && ($rest === '' || $rest[0] === '/')) {
                $best = $name;
            }
        }

        if ($best !== '') {
            return [$best, trim(ltrim(substr($target, strlen($best)), '/'))];
        }

        [$department, $role] = array_pad(explode('/', $target, 2), 2, '');

        return [trim($department), trim($role)];
    }

    public static function ticketId(string $subject): ?int
    {
        if (preg_match('/\[#(\d{1,9})\]/', $subject, $m) !== 1) {
            return null;
        }

        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }

    // drops re, fw and the ticket tag so the subject reads as a title
    public static function cleanSubject(string $subject): string
    {
        $subject = preg_replace('/\[#\d{1,9}\]/', '', $subject) ?? $subject;

        do {
            $before  = $subject;
            $subject = preg_replace('/^\s*(re|fw|fwd|aw|wg|sv|tr)\s*(\[\d+\])?\s*:\s*/i', '', $subject) ?? $subject;
        } while ($subject !== $before);

        return trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);
    }

    // cuts the quoted history that mail clients add below a reply
    public static function stripQuoted(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $keep  = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (self::isMarker($lines, $i)) {
                break;
            }
            if (str_starts_with(ltrim($lines[$i]), '>')) {
                continue;
            }
            $keep[] = rtrim($lines[$i]);
        }

        $out = implode("\n", $keep);
        $out = preg_replace('/\n{3,}/', "\n\n", $out) ?? $out;

        return trim($out);
    }

    private static function isMarker(array $lines, int $i): bool
    {
        $line = trim($lines[$i]);

        if ($line === '') {
            return false;
        }

        if (preg_match('/^-{2,}\s*(original message|forwarded message)\s*-{2,}/i', $line) === 1) {
            return true;
        }

        if (preg_match('/^_{10,}$/', $line) === 1) {
            return true;
        }

        // gmail and apple mail, sometimes wrapped onto a second line
        if (mb_strlen($line) < 300 && preg_match('/^on\s/i', $line) === 1) {
            if (preg_match('/wrote:$/i', $line) === 1) {
                return true;
            }
            $next = trim($lines[$i + 1] ?? '');
            if ($next !== '' && mb_strlen($next) < 200 && preg_match('/wrote:$/i', $next) === 1) {
                return true;
            }
        }

        // outlook header block
        if (preg_match('/^\*?from:\*?\s/i', $line) === 1) {
            $last = min($i + 4, count($lines) - 1);
            for ($j = $i + 1; $j <= $last; $j++) {
                if (preg_match('/^\*?(sent|date|to|subject):/i', trim($lines[$j])) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    // no-reply senders, bounces and auto replies must never make tickets
    public static function isAutomated(string $from, array $headers): bool
    {
        $local = strtolower((string) strstr(trim($from), '@', true));

        if ($local === '' || preg_match('/no[-_.]?reply|do[-_.]?not[-_.]?reply|mailer[-_.]?daemon|^postmaster$/', $local) === 1) {
            return true;
        }

        foreach ($headers as $header) {
            $name  = strtolower(trim((string) ($header['name'] ?? '')));
            $value = strtolower(trim((string) ($header['value'] ?? '')));

            if ($name === 'auto-submitted' && $value !== 'no') {
                return true;
            }
        }

        return false;
    }

    // plain text from a graph body, quoted history removed, capped
    public static function bodyText(string $content, bool $isHtml): string
    {
        if ($isHtml) {
            $content = self::cutHtmlQuote($content);
            $html    = new Html();
            $content = $html->toText($html->clean($content));
        }

        $text = self::stripQuoted($content);

        return mb_strlen($text) > self::MAX_BODY ? rtrim(mb_substr($text, 0, self::MAX_BODY)) : $text;
    }

    private static function cutHtmlQuote(string $html): string
    {
        if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $m) === 1) {
            $html = $m[1];
        }

        $html = preg_replace('#<head\b.*?</head>#is', '', $html) ?? $html;

        $markers = '#<div[^>]+(class="gmail_quote[^"]*"|id="divRplyFwdMsg"|id="appendonsend")|<blockquote[^>]+type="cite"#i';

        if (preg_match($markers, $html, $m, PREG_OFFSET_CAPTURE) === 1) {
            $html = substr($html, 0, (int) $m[0][1]);
        }

        return $html;
    }
}
