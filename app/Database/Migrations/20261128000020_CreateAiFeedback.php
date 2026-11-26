<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// requester votes on assistant answers, one per user per message
class CreateAiFeedback extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'message_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'helpful'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['message_id', 'user_id']);
        $this->forge->createTable('ai_feedback', true);
    }

    public function down()
    {
        $this->forge->dropTable('ai_feedback', true);
    }
}
