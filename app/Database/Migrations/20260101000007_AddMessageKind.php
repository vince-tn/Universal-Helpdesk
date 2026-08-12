<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMessageKind extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ticket_messages', [
            'kind' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'message',
                'after'      => 'author_role',
            ],
            'ai_confidence' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'kind',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ticket_messages', ['kind', 'ai_confidence']);
    }
}
