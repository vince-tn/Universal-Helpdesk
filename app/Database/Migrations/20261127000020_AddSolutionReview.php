<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// ticked solutions wait for a lead to approve them before the assistant reuses them
class AddSolutionReview extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ticket_messages', [
            'solution_state'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'after' => 'is_solution'],
            'solution_reviewed_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'solution_state'],
            'solution_reviewed_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'solution_reviewed_by'],
        ]);

        $this->db->query('CREATE INDEX idx_msg_solution_state ON ticket_messages (solution_state)');

        // answers already in use stay in use
        $this->db->table('ticket_messages')->where('is_solution', 1)->update(['solution_state' => 'approved']);
    }

    public function down()
    {
        $this->forge->dropColumn('ticket_messages', ['solution_state', 'solution_reviewed_by', 'solution_reviewed_at']);
    }
}
