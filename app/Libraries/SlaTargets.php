<?php

namespace App\Libraries;

// pure checks for the sla targets form, hours in and minutes out
final class SlaTargets
{
    public const MAX_MINUTES = 129600;

    // returns [response, resolution] in minutes or an error message
    public static function validate(string $responseHours, string $resolutionHours): array|string
    {
        $response   = self::minutes($responseHours);
        $resolution = self::minutes($resolutionHours);

        if ($response === null || $resolution === null) {
            return 'Enter hours as a number, like 4 or 1.5.';
        }
        if ($response < 1 || $resolution < 1) {
            return 'Times must be more than zero.';
        }
        if ($response > self::MAX_MINUTES || $resolution > self::MAX_MINUTES) {
            return 'Times can be at most 90 days (2160 hours).';
        }
        if ($response >= $resolution) {
            return 'First response must be shorter than resolution.';
        }

        return [$response, $resolution];
    }

    public static function minutes(string $hours): ?int
    {
        $hours = trim($hours);

        if (preg_match('/^(\d{1,6}(\.\d{0,4})?|\.\d{1,4})$/', $hours) !== 1) {
            return null;
        }

        return (int) round((float) $hours * 60);
    }

    // value for an hours input, no trailing zeros
    public static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
    }

    // sorted list of targets so two tables can be compared
    public static function snapshot(array $rows): array
    {
        $out = array_map(static fn ($r) => [
            'category'           => (string) $r['category'],
            'priority'           => (string) $r['priority'],
            'response_minutes'   => (int) $r['response_minutes'],
            'resolution_minutes' => (int) $r['resolution_minutes'],
        ], $rows);

        usort($out, static fn ($a, $b) => [$a['category'], $a['priority']] <=> [$b['category'], $b['priority']]);

        return $out;
    }

    public static function same(array $a, array $b): bool
    {
        return self::snapshot($a) === self::snapshot($b);
    }
}
