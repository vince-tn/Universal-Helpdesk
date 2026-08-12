<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTicketAiColumns extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tickets', [

            'ai_updated_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'needs_human_review',
            ],

            'ai_resolution_note' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'ai_updated_at',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tickets', ['ai_updated_at', 'ai_resolution_note']);
    }
}
