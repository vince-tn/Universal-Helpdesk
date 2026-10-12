<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIntakeAsyncAndRole extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tickets', [
            'submitting_role' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
                'after'      => 'submitting_department',
            ],
            'classified_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'ai_source',
            ],
        ]);

        $this->db->query('UPDATE tickets SET classified_at = created_at WHERE classified_at IS NULL');
    }

    public function down()
    {
        $this->forge->dropColumn('tickets', ['submitting_role', 'classified_at']);
    }
}
