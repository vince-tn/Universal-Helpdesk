<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// key value settings and cached readings of image attachments
class CreateAppSettings extends Migration
{
    public function up()
    {
        $dt = ['type' => 'DATETIME', 'null' => true];

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 80],
            'value'      => ['type' => 'TEXT', 'null' => true],
            'updated_at' => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('app_settings', true);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'ticket_id'  => ['type' => 'VARCHAR', 'constraint' => 100],
            'file'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'model'      => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'reading'    => ['type' => 'TEXT', 'null' => true],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'done'],
            'created_at' => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['ticket_id', 'file']);
        $this->forge->createTable('attachment_readings', true);
    }

    public function down()
    {
        $this->forge->dropTable('attachment_readings', true);
        $this->forge->dropTable('app_settings', true);
    }
}
