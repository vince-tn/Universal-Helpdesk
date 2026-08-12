<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailVerificationModel extends Model
{
    protected $table         = 'email_verifications';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $allowedFields = [
        'email', 'purpose', 'code_hash', 'payload',
        'attempts', 'expires_at', 'consumed_at', 'ip_address',
    ];

    public function activeFor(string $email, string $purpose = 'signup'): ?array
    {
        return $this->where('email', strtolower(trim($email)))
            ->where('purpose', $purpose)
            ->where('consumed_at', null)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function latestFor(string $email, string $purpose = 'signup'): ?array
    {
        return $this->where('email', strtolower(trim($email)))
            ->where('purpose', $purpose)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function invalidateFor(string $email, string $purpose = 'signup'): void
    {
        $this->where('email', strtolower(trim($email)))
            ->where('purpose', $purpose)
            ->where('consumed_at', null)
            ->set(['consumed_at' => date('Y-m-d H:i:s')])
            ->update();
    }

    public function sendsSince(string $email, string $since, string $purpose = 'signup'): int
    {
        return $this->where('email', strtolower(trim($email)))
            ->where('purpose', $purpose)
            ->where('created_at >=', $since)
            ->countAllResults();
    }

    public function markConsumed(int $id): bool
    {
        return $this->update($id, ['consumed_at' => date('Y-m-d H:i:s')]);
    }

    public function recordAttempt(int $id, int $attempts): bool
    {
        return $this->update($id, ['attempts' => $attempts]);
    }

    public function prune(int $olderThanDays = 7): void
    {
        $this->where('created_at <', date('Y-m-d H:i:s', strtotime("-{$olderThanDays} days")))
            ->delete();
    }
}
