<?php

namespace App\Libraries;

class PriorCases
{

    public function context(array $prior): string
    {
        if ($prior === []) {
            return '';
        }

        $blocks = [];

        foreach ($prior as $i => $row) {
            $blocks[] = implode("\n", [
                'CASE ' . ($i + 1) . ' (ticket #' . $row['id'] . ', already resolved)',
                'They asked: ' . $row['request'],
                'What resolved it: ' . $row['resolution'],
            ]);
        }

        return implode("\n\n", $blocks);
    }

    public function resolutionFor(array $prior, array $output): string
    {
        $case = (int) trim((string) ($output['matched_prior'] ?? ''));

        if ($case < 1 || $case > count($prior)) {
            return '';
        }

        return (string) ($prior[$case - 1]['resolution'] ?? '');
    }
}
