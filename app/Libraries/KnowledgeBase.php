<?php

namespace App\Libraries;

use App\Models\DepartmentModel;

class KnowledgeBase
{
    private const LIMIT      = 3;
    private const BODY_CHARS = 900;

    private const MIN_SCORE      = 0.01;
    private const RELATIVE_SCORE = 0.2;

    public const MIN_MEANING = 0.45;
    public const MARGIN      = 0.12;
    public const WORD_WEIGHT = 0.15;

    public function relevant(string $department, string $text, int $limit = self::LIMIT): array
    {
        $text = trim($text);

        if (mb_strlen($text) < 3) {
            return [];
        }

        $departmentId = (new DepartmentModel())->idFor($department);

        try {
            $byMeaning = $this->byMeaning($departmentId, $text, $limit);
        } catch (\Throwable $e) {
            log_message('error', 'Search by meaning failed, using word search: {message}', ['message' => $e->getMessage()]);
            $byMeaning = null;
        }

        return $byMeaning ?? $this->byWords($departmentId, $text, $limit);
    }

    public static function rank(array $articles, array $query, array $wordScores, int $limit = self::LIMIT): array
    {
        $bestWords = $wordScores === [] ? 0.0 : (float) max($wordScores);
        $scored    = [];

        foreach ($articles as $article) {
            $meaning = Embeddings::cosine($query, (array) ($article['vector'] ?? []));

            if ($meaning < self::MIN_MEANING) {
                continue;
            }

            $words = $bestWords > 0.0 ? (float) ($wordScores[(int) $article['id']] ?? 0.0) / $bestWords : 0.0;

            unset($article['vector'], $article['embedding'], $article['embedding_model'], $article['embedding_hash']);

            $scored[] = $article + [
                'score'   => round($meaning + self::WORD_WEIGHT * $words, 4),
                'meaning' => round($meaning, 4),
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $floor = ($scored[0]['score'] ?? 0.0) - self::MARGIN;

        return array_slice(array_values(array_filter($scored, static fn ($a) => $a['score'] >= $floor)), 0, $limit);
    }

    private function scoped(?int $departmentId)
    {
        $builder = db_connect()->table('knowledge_articles')
            ->where('is_active', 1)
            ->groupStart()
                ->where('department_id IS NULL', null, false);

        if ($departmentId !== null) {
            $builder->orWhere('department_id', $departmentId);
        }

        return $builder->groupEnd();
    }

    private function byMeaning(?int $departmentId, string $text, int $limit): ?array
    {
        $embeddings = new Embeddings();

        if (! $embeddings->enabled()) {
            return null;
        }

        $articles = $this->scoped($departmentId)
            ->select('id, department_id, title, keywords, body, embedding, embedding_model, embedding_hash')
            ->get()
            ->getResultArray();

        if ($articles === []) {
            return [];
        }

        $articles = $this->withVectors($articles, $embeddings);

        if ($articles === null) {
            return null;
        }

        $query = $embeddings->embed([$embeddings->query($text)]);

        if ($query === null || $query === []) {
            return null;
        }

        return self::rank($articles, $query[0], $this->wordScores($departmentId, $text), $limit);
    }

    private function withVectors(array $articles, Embeddings $embeddings): ?array
    {
        $stale = [];

        foreach ($articles as $i => $article) {
            $hash   = sha1($embeddings->model() . "\n" . Embeddings::document($article));
            $vector = $article['embedding_hash'] === $hash ? json_decode((string) $article['embedding'], true) : null;

            if (is_array($vector) && $vector !== []) {
                $articles[$i]['vector'] = $vector;
            } else {
                $stale[$i] = $hash;
            }
        }

        if ($stale === []) {
            return $articles;
        }

        $vectors = $embeddings->embed(array_map(static fn ($i) => Embeddings::document($articles[$i]), array_keys($stale)));

        if ($vectors === null) {
            return null;
        }

        $table = db_connect()->table('knowledge_articles');

        foreach (array_keys($stale) as $n => $i) {
            $articles[$i]['vector'] = $vectors[$n];

            $table->where('id', (int) $articles[$i]['id'])->update([
                'embedding'       => json_encode(array_map(static fn ($v) => round($v, 6), $vectors[$n])),
                'embedding_model' => $embeddings->model(),
                'embedding_hash'  => $stale[$i],
            ]);
        }

        return $articles;
    }

    private function wordScores(?int $departmentId, string $text): array
    {
        $db = db_connect();

        if (! str_contains(strtolower($db->DBDriver), 'mysql')) {
            return [];
        }

        $match  = 'MATCH(title, keywords, body) AGAINST (' . $db->escape($text) . ' IN NATURAL LANGUAGE MODE)';
        $scores = [];

        foreach ($this->scoped($departmentId)->select('id, ' . $match . ' AS score', false)->where($match, null, false)->get()->getResultArray() as $row) {
            $scores[(int) $row['id']] = (float) $row['score'];
        }

        return $scores;
    }

    private function byWords(?int $departmentId, string $text, int $limit): array
    {
        $db      = db_connect();
        $builder = $this->scoped($departmentId);

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

        return $builder->select('id, department_id, title, keywords, body')->orderBy('id', 'ASC')->limit($limit)->get()->getResultArray();
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
