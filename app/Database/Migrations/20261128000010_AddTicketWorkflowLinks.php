<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// auto assignment, request for someone else, watchers, duplicates and linked tickets
class AddTicketWorkflowLinks extends Migration
{
    public function up()
    {
        $this->forge->addColumn('departments', [
            'assignment_mode'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'none'],
            'default_assignee_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);

        $this->forge->addColumn('users', [
            'last_assigned_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addColumn('tickets', [
            'requested_for' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'duplicate_of'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'ticket_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'added_by'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['ticket_id', 'user_id']);
        $this->forge->addKey('user_id');
        $this->forge->createTable('ticket_watchers', true);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'ticket_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'linked_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['ticket_id', 'linked_id']);
        $this->forge->addKey('linked_id');
        $this->forge->createTable('ticket_links', true);
    }

    public function down()
    {
        $this->forge->dropTable('ticket_links', true);
        $this->forge->dropTable('ticket_watchers', true);
        $this->forge->dropColumn('tickets', ['requested_for', 'duplicate_of']);
        $this->forge->dropColumn('users', 'last_assigned_at');
        $this->forge->dropColumn('departments', ['assignment_mode', 'default_assignee_id']);
    }
}
