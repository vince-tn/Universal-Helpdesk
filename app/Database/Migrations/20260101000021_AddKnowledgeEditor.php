<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddKnowledgeEditor extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'can_edit_knowledge' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'department_role_id',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'can_edit_knowledge');
    }
}
