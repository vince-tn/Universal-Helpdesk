<?php

namespace App\Models;

use CodeIgniter\Model;

class SignupAllowlistModel extends Model
{
    protected $table         = 'signup_allowlist';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['kind', 'value', 'label', 'created_by'];

    public const KIND_DOMAIN = 'domain';
    public const KIND_EMAIL  = 'email';

    public const LABEL_MAX = 150;

    public function listed(): array
    {
        return $this->orderBy('kind', 'ASC')->orderBy('value', 'ASC')->findAll();
    }

    /** Every listed value of one kind, lower-cased. */
    public function values(string $kind): array
    {
        return array_map(
            static fn ($row) => strtolower((string) $row['value']),
            $this->where('kind', $kind)->findAll()
        );
    }
}
