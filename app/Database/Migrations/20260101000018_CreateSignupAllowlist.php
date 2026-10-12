<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSignupAllowlist extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'kind'       => ['type' => 'VARCHAR', 'constraint' => 10],
            'value'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'label'      => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('value');
        $this->forge->createTable('signup_allowlist');
    }

    public function down()
    {
        $this->forge->dropTable('signup_allowlist');
    }
}
