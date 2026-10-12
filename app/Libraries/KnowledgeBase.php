<?php

namespace App\Libraries;

use App\Models\DepartmentModel;

class KnowledgeBase
{
    private const LIMIT      = 3;
    private const BODY_CHARS = 900;

    private const MIN_SCORE      = 0.01;
    private const RELATIVE_SCORE = 0.2;

    public function relevant(string $department, string $text, int $limit = self::LIMIT): array
    {
        $text = trim($text);

        if (mb_strlen($text) < 3) {
            return [];
        }

        $db           = db_connect();
        $departmentId = (new DepartmentModel())->idFor($department);

        $builder = $db->table('knowledge_articles')
            ->where('is_active', 1)
            ->groupStart()
                ->where('department_id IS NULL', null, false);

        if ($departmentId !== null) {
            $builder->orWhere('department_id', $departmentId);
        }

        $builder->groupEnd();

        if (str_contains(strtolower($db->DBDriver), 'mysql')) {
            $match = 'MATCH(title, keywords, body) AGAINST (' . $db->escape($text) . ' IN NATURAL LANGUAGE MODE)';

            $rows = $builder
                ->select('id, department_id, title, keywords, body, ' . $match . ' AS score', false)
                ->where($match, null, false)
                ->orderBy('score', 'DESC')
                ->limit($limit * 3)
                ->get()
                ->getResultArray();

            return $this->strongest($rows, $limit);
        }

        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($text), $m);
        $words = array_slice(array_unique($m[0] ?? []), 0, 6);

        if ($words === []) {
            return [];
        }

        $builder->groupStart();
        foreach ($words as $word) {
            $builder->orLike('title', $word)->orLike('keywords', $word)->orLike('body', $word);
        }
        $builder->groupEnd();

        return $builder->orderBy('id', 'ASC')->limit($limit)->get()->getResultArray();
    }

    private function strongest(array $rows, int $limit): array
    {
        if ($rows === []) {
            return [];
        }

        $best  = (float) $rows[0]['score'];
        $floor = max(self::MIN_SCORE, $best * self::RELATIVE_SCORE);

        return array_slice(
            array_values(array_filter($rows, static fn ($r) => (float) $r['score'] >= $floor)),
            0,
            $limit
        );
    }

    public function context(array $articles): string
    {
        $blocks = [];

        foreach (array_values($articles) as $i => $article) {
            $blocks[] = 'ARTICLE ' . ($i + 1) . ': ' . trim((string) $article['title']) . "\n" . $this->excerpt($article);
        }

        return implode("\n\n", $blocks);
    }

    public function excerpt(array $article): string
    {
        $body = trim((string) $article['body']);

        return mb_strlen($body) > self::BODY_CHARS
            ? rtrim(mb_substr($body, 0, self::BODY_CHARS)) . ' [...]'
            : $body;
    }
}
