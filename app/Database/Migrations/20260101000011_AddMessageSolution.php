<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMessageSolution extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ticket_messages', [
            'is_solution' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'ai_confidence',
            ],
        ]);

        $this->db->query('CREATE INDEX idx_msg_solution ON ticket_messages (ticket_id, is_solution)');
    }

    public function down()
    {
        $this->forge->dropColumn('ticket_messages', 'is_solution');
    }
}
