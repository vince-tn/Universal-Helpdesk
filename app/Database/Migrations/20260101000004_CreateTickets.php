<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTickets extends Migration
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

            'requester' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],
            'requester_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 190,
            ],
            'submitting_department' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],

            'request_body' => [
                'type' => 'TEXT',
                'null' => true,
            ],

            'request_title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'category' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'responsible_department' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'expected_deliverable' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'suggested_tat' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'requirements_needed' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'closure_criteria' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'ai_source' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'Local',
            ],
            'matched_catalogue_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'needs_human_review' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],

            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'New',
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
        $this->forge->addKey('requester_email');
        $this->forge->addKey('status');
        $this->forge->addKey('responsible_department');
        $this->forge->createTable('tickets');
    }

    public function down()
    {
        $this->forge->dropTable('tickets', true);
    }
}
