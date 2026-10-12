<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddOrgHierarchy extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'company' => [
                'type'       => 'VARCHAR',
                'constraint' => 60,
                'null'       => true,
                'after'      => 'email',
            ],
        ]);

        $this->forge->addColumn('departments', [
            'supervisor_id' => [
                'type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'name',
            ],
            'team_leader_id' => [
                'type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'supervisor_id',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'company');
        $this->forge->dropColumn('departments', ['supervisor_id', 'team_leader_id']);
    }
}
