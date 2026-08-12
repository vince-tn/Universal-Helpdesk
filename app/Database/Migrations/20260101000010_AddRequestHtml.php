<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddRequestHtml extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tickets', [
            'request_html' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'request_body',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tickets', 'request_html');
    }
}
