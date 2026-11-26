<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// forced password change flag and emailed reset codes
class AddPasswordSelfService extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('must_change_password', 'users')) {
            $this->forge->addColumn('users', [
                'must_change_password' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            ]);
        }

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'email'       => ['type' => 'VARCHAR', 'constraint' => 190],
            'code_hash'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'expires_at'  => ['type' => 'DATETIME'],
            'attempts'    => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'default' => 0],
            'consumed_at' => ['type' => 'DATETIME', 'null' => true],
            'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('email');
        $this->forge->addKey('created_at');
        $this->forge->createTable('password_resets', true);
    }

    public function down()
    {
        $this->forge->dropTable('password_resets', true);

        if ($this->db->fieldExists('must_change_password', 'users')) {
            $this->forge->dropColumn('users', 'must_change_password');
        }
    }
}
