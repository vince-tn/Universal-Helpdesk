<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// two step sign in secrets and the entra id link on users
class AddMfaAndSso extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'mfa_secret'    => ['type' => 'TEXT', 'null' => true],
            'mfa_enabled'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'mfa_recovery'  => ['type' => 'TEXT', 'null' => true],
            'mfa_last_step' => ['type' => 'BIGINT', 'null' => true],
            'sso_subject'   => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
        ]);

        $this->forge->addKey('sso_subject', false, false, 'users_sso_subject');
        $this->forge->processIndexes('users');
    }

    public function down()
    {
        $this->forge->dropColumn('users', ['mfa_secret', 'mfa_enabled', 'mfa_recovery', 'mfa_last_step', 'sso_subject']);
    }
}
