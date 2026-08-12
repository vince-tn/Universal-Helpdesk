<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedDemoAccounts extends Migration
{
    public function up()
    {
        if (ENVIRONMENT === 'production') {
            return;
        }

        $accounts = [
            [
                'name'       => 'Ada Agent',
                'email'      => 'agent@universalhelpdesk.local',
                'role'       => 'agent',
                'department' => 'IT',
            ],
            [
                'name'       => 'Riley Requester',
                'email'      => 'requester@universalhelpdesk.local',
                'role'       => 'requester',
                'department' => null,
            ],
        ];

        $now = date('Y-m-d H:i:s');

        foreach ($accounts as $account) {
            $taken = $this->db->table('users')
                ->where('email', $account['email'])
                ->countAllResults() > 0;

            if ($taken) {
                continue;
            }

            $this->db->table('users')->insert($account + [
                'password_hash' => password_hash('ChangeMe123!', PASSWORD_DEFAULT),
                'is_active'     => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function down()
    {
        $this->db->table('users')
            ->whereIn('email', [
                'agent@universalhelpdesk.local',
                'requester@universalhelpdesk.local',
            ])
            ->delete();
    }
}
