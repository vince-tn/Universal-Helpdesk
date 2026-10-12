<?php

namespace App\Libraries;

use App\Models\DepartmentModel;
use App\Models\GuardrailModel;

/**
 * Department rules for the assistant, in three strengths:
 *
 *  instruction - sent to the model as a rule. It usually obeys; nothing checks.
 *  handoff     - a topic. If the requester's text mentions it, the assistant
 *                gives no advice at all, whatever the model said. Enforced here.
 *  block       - a phrase. If the model's reply contains it, the reply is
 *                replaced before anyone sees it. Enforced here.
 *
 * Rules with no department apply to every department.
 */
class Guardrails
{
    private const INSTRUCTION_CHARS = 1500;

    private array $cache = [];

    public function rules(string $department): array
    {
        $departmentId = (new DepartmentModel())->idFor($department);
        $key          = (string) ($departmentId ?? 'global');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $builder = db_connect()->table('guardrails')
            ->groupStart()
                ->where('department_id IS NULL', null, false);

        if ($departmentId !== null) {
            $builder->orWhere('department_id', $departmentId);
        }

        $rows = $builder->groupEnd()->orderBy('id', 'ASC')->get()->getResultArray();

        $rules = array_fill_keys(GuardrailModel::KINDS, []);

        foreach ($rows as $row) {
            if (isset($rules[$row['kind']])) {
                $rules[$row['kind']][] = trim((string) $row['value']);
            }
        }

        return $this->cache[$key] = $rules;
    }

    public function instructions(string $department): string
    {
        $out = '';

        foreach ($this->rules($department)[GuardrailModel::KIND_INSTRUCTION] as $line) {
            $next = '- ' . $line . "\n";

            if (mb_strlen($out . $next) > self::INSTRUCTION_CHARS) {
                break;
            }

            $out .= $next;
        }

        return trim($out);
    }

    public function handoffTopic(string $department, string $text): ?string
    {
        return self::firstMatch($this->rules($department)[GuardrailModel::KIND_HANDOFF], $text);
    }

    public function blockedPhrase(string $department, string $text): ?string
    {
        return self::firstMatch($this->rules($department)[GuardrailModel::KIND_BLOCK], $text);
    }

    /**
     * Whole words or phrases, case-insensitive: "pay" does not match "payroll".
     * A trailing * matches any ending: "pay*" matches pay, payroll and payslip.
     */
    public static function firstMatch(array $terms, string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        foreach ($terms as $term) {
            $term = trim((string) $term);
            $stem = rtrim($term, '*');

            if ($stem === '') {
                continue;
            }

            $words   = preg_split('/\s+/u', $stem) ?: [$stem];
            $body    = implode('\s+', array_map(static fn ($w) => preg_quote($w, '/'), $words));
            $tail    = str_ends_with($term, '*') ? '' : '(?![\p{L}\p{N}])';
            $pattern = '/(?<![\p{L}\p{N}])' . $body . $tail . '/iu';

            if (preg_match($pattern, $text) === 1) {
                return $term;
            }
        }

        return null;
    }
}
