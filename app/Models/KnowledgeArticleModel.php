<?php

namespace App\Models;

use CodeIgniter\Model;

class KnowledgeArticleModel extends Model
{
    protected $table         = 'knowledge_articles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['department_id', 'title', 'keywords', 'body', 'is_active'];

    public const TITLE_MAX    = 150;
    public const KEYWORDS_MAX = 255;
    public const BODY_MAX     = 2000;

    public function forDepartment(?int $departmentId): array
    {
        return $this->where('department_id', $departmentId)->orderBy('title', 'ASC')->findAll();
    }

    public function countsByDepartment(): array
    {
        $rows = $this->db->table($this->table)
            ->select('department_id, COUNT(*) AS n')
            ->where('is_active', 1)
            ->groupBy('department_id')
            ->get()
            ->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['department_id'] === null ? 'all' : (int) $row['department_id']] = (int) $row['n'];
        }

        return $counts;
    }
}
