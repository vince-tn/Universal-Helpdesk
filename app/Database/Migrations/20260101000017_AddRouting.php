<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddRouting extends Migration
{
    public function up()
    {
        $handles = ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'after' => 'name'];

        $this->forge->addColumn('departments', ['handles' => $handles]);
        $this->forge->addColumn('department_roles', ['handles' => $handles]);

        // submitting_department / submitting_role stay exactly what the requester
        // picked. The ticket is owned by responsible_department / responsible_role,
        // and routing_note records who moved it, if anyone did.
        $this->forge->addColumn('tickets', [
            'responsible_role' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
                'after'      => 'responsible_department',
            ],
            'routing_note' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => true,
                'after'      => 'responsible_role',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tickets', ['responsible_role', 'routing_note']);
        $this->forge->dropColumn('department_roles', 'handles');
        $this->forge->dropColumn('departments', 'handles');
    }
}
