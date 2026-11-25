<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// one row per graph message so email intake never handles a message twice
class CreateMailIntakeLog extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'message_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'message_id'   => ['type' => 'VARCHAR', 'constraint' => 512],
            'mailbox'      => ['type' => 'VARCHAR', 'constraint' => 190],
            'sender'       => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'subject'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'outcome'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'working'],
            'ticket_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'detail'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('message_hash');
        $this->forge->addKey('created_at');
        $this->forge->createTable('mail_intake_log', true);
    }

    public function down()
    {
        $this->forge->dropTable('mail_intake_log', true);
    }
}
