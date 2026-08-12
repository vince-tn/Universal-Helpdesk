<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEmailVerifications extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
            ],

            'purpose' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'signup',
            ],
            'code_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],

            'payload' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'attempts' => [
                'type'       => 'TINYINT',
                'constraint' => 2,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'consumed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['email', 'purpose']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('email_verifications');
    }

    public function down()
    {
        $this->forge->dropTable('email_verifications', true);
    }
}
