<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDepartmentContext extends Migration
{
    public function up()
    {
        $this->forge->addColumn('departments', [
            'ai_context' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'name',
            ],
            'ai_guardrails' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'ai_context',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('departments', ['ai_context', 'ai_guardrails']);
    }
}
