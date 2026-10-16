<?php

namespace App\Models;

use CodeIgniter\Model;

class KnowledgeRevisionModel extends Model
{
    protected $table         = 'knowledge_article_revisions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'article_id', 'department_id', 'action', 'title', 'keywords',
        'body', 'is_active', 'changed_by', 'changed_by_name', 'created_at',
    ];

    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';

    public function record(int $articleId, array $article, string $action, ?array $user): int|string|false
    {
        return $this->insert([
            'article_id'      => $articleId,
            'department_id'   => ($article['department_id'] ?? null) === null ? null : (int) $article['department_id'],
            'action'          => $action,
            'title'           => mb_substr((string) ($article['title'] ?? ''), 0, 150),
            'keywords'        => ($article['keywords'] ?? null) === null ? null : mb_substr((string) $article['keywords'], 0, 255),
            'body'            => (string) ($article['body'] ?? ''),
            'is_active'       => (int) ($article['is_active'] ?? 1),
            'changed_by'      => $user === null ? null : (int) $user['id'],
            'changed_by_name' => mb_substr((string) ($user['name'] ?? 'System'), 0, 100),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public function forArticle(int $articleId): array
    {
        return $this->where('article_id', $articleId)->orderBy('id', 'DESC')->findAll();
    }

    public function latestIdFor(int $articleId): ?int
    {
        $row = $this->select('id')->where('article_id', $articleId)->orderBy('id', 'DESC')->first();

        return $row === null ? null : (int) $row['id'];
    }

    public function asOf(int $articleId, string $when): ?array
    {
        return $this->where('article_id', $articleId)
            ->where('created_at <=', $when)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function countsByArticle(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $rows = $this->db->table($this->table)
            ->select('article_id, COUNT(*) AS n')
            ->whereIn('article_id', $articleIds)
            ->groupBy('article_id')
            ->get()
            ->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['article_id']] = (int) $row['n'];
        }

        return $counts;
    }
}
