<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// who signed off the sla targets, with a copy of the table at that moment
class CreateSlaSignoffs extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 150],
            'note'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'snapshot'   => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('sla_signoffs', true);
    }

    public function down()
    {
        $this->forge->dropTable('sla_signoffs', true);
    }
}
