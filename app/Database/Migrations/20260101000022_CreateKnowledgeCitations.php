<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateKnowledgeCitations extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'message_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'ticket_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'article_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'revision_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('article_id');
        $this->forge->addKey('message_id');
        $this->forge->createTable('knowledge_citations');
    }

    public function down()
    {
        $this->forge->dropTable('knowledge_citations', true);
    }
}
