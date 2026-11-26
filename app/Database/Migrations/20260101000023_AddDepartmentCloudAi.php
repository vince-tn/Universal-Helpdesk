<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDepartmentCloudAi extends Migration
{
    public function up()
    {
        $this->forge->addColumn('departments', [
            'allow_cloud_ai' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
                'after'      => 'handles',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('departments', 'allow_cloud_ai');
    }
}
