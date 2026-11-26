<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// optional end date for temporary accounts
class AddUserExpiry extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'expires_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'expires_at');
    }
}
