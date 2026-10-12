<?php

namespace App\Models;

use CodeIgniter\Model;

class GuardrailModel extends Model
{
    protected $table         = 'guardrails';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['department_id', 'kind', 'value'];

    public const KIND_INSTRUCTION = 'instruction';
    public const KIND_HANDOFF     = 'handoff';
    public const KIND_BLOCK       = 'block';

    public const KINDS = [self::KIND_INSTRUCTION, self::KIND_HANDOFF, self::KIND_BLOCK];

    public const VALUE_MAX = [
        self::KIND_INSTRUCTION => 300,
        self::KIND_HANDOFF     => 100,
        self::KIND_BLOCK       => 100,
    ];

    public function forDepartment(?int $departmentId): array
    {
        return $this->where('department_id', $departmentId)->orderBy('kind', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    public function countsByDepartment(): array
    {
        $rows = $this->db->table($this->table)
            ->select('department_id, COUNT(*) AS n')
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
