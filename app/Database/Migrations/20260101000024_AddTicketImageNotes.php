<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTicketImageNotes extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tickets', [
            'image_notes' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'request_html',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tickets', 'image_notes');
    }
}
