<?php

namespace App\Libraries;

use App\Models\UserModel;

class ExportGuard
{
    public const ROLES = [UserModel::ROLE_SUPERADMIN, UserModel::ROLE_SUPERVISOR];

    public static function allowed(?array $user): bool
    {
        return in_array($user['role'] ?? '', self::ROLES, true);
    }

    // writes an export row to audit_log once that table exists
    public static function log(?array $user, string $detail): void
    {
        try {
            $db = \Config\Database::connect();

            if (! $db->tableExists('audit_log')) {
                return;
            }

            $db->table('audit_log')->insert([
                'user_id'    => isset($user['id']) ? (int) $user['id'] : null,
                'actor_name' => mb_substr((string) ($user['name'] ?? ''), 0, 150),
                'action'     => 'export',
                'ticket_id'  => null,
                'detail'     => mb_substr($detail, 0, 500),
                'ip'         => mb_substr((string) service('request')->getIPAddress(), 0, 45),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'audit log write failed: ' . $e->getMessage());
        }
    }
}
