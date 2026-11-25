<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// sla, approvals, notes, activity log, audit, feedback, catalogue, mail queue
class AddServiceDeskWorkflow extends Migration
{
    public function up()
    {
        $dt  = ['type' => 'DATETIME', 'null' => true];
        $int = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true];

        $this->forge->addColumn('ticket_meta', [
            'response_due_at'    => $dt,
            'due_at'             => $dt,
            'first_response_at'  => $dt,
            'resolved_at'        => $dt,
            'paused_at'          => $dt,
            'sla_paused_minutes' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'reopened_count'     => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'sla_warned_at'      => $dt,
            'needed_by'          => ['type' => 'DATE', 'null' => true],
            'people_affected'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'work_stopped'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);

        $this->forge->addColumn('tickets', [
            'requester_department' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'requester_email'],
            'service_key'          => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'service_fields'       => ['type' => 'TEXT', 'null' => true],
            'is_confidential'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'approval_state'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'resolution_note'      => ['type' => 'TEXT', 'null' => true],
            'routing_reason'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);

        $this->forge->addColumn('departments', [
            'is_confidential' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);
        $this->forge->addColumn('department_roles', [
            'is_confidential' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);

        $this->forge->addColumn('ticket_messages', [
            'is_internal' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);

        $this->forge->addColumn('users', [
            'notify_email' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);

        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'category'           => ['type' => 'VARCHAR', 'constraint' => 50],
            'priority'           => ['type' => 'VARCHAR', 'constraint' => 10],
            'response_minutes'   => ['type' => 'INT', 'constraint' => 11],
            'resolution_minutes' => ['type' => 'INT', 'constraint' => 11],
            'created_at'         => $dt,
            'updated_at'         => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['category', 'priority']);
        $this->forge->createTable('sla_policies', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'ticket_id'   => ['type' => 'VARCHAR', 'constraint' => 100],
            'user_id'     => $int,
            'actor_name'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'event'       => ['type' => 'VARCHAR', 'constraint' => 40],
            'from_value'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'to_value'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'  => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('ticket_id');
        $this->forge->createTable('ticket_events', true);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => $int,
            'actor_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'action'     => ['type' => 'VARCHAR', 'constraint' => 60],
            'ticket_id'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'detail'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at' => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('action');
        $this->forge->createTable('audit_log', true);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'ticket_id'  => ['type' => 'VARCHAR', 'constraint' => 100],
            'rating'     => ['type' => 'TINYINT', 'constraint' => 1],
            'comment'    => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'token'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at' => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('ticket_id');
        $this->forge->createTable('ticket_feedback', true);

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'ticket_id'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'step'          => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'approver_role' => ['type' => 'VARCHAR', 'constraint' => 20],
            'approver_id'   => $int,
            'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'decided_by'    => $int,
            'decided_at'    => $dt,
            'note'          => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'    => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['ticket_id', 'step']);
        $this->forge->createTable('ticket_approvals', true);

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'department_id' => $int,
            'title'         => ['type' => 'VARCHAR', 'constraint' => 120],
            'body'          => ['type' => 'TEXT'],
            'created_at'    => $dt,
            'updated_at'    => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('canned_responses', true);

        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'service_key'       => ['type' => 'VARCHAR', 'constraint' => 60],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 120],
            'description'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'department'        => ['type' => 'VARCHAR', 'constraint' => 80],
            'role'              => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'category'          => ['type' => 'VARCHAR', 'constraint' => 50, 'default' => 'Common Request'],
            'fields_json'       => ['type' => 'TEXT', 'null' => true],
            'requires_approval' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'approval_chain'    => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'is_active'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sort_order'        => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at'        => $dt,
            'updated_at'        => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('service_key');
        $this->forge->createTable('services', true);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'to_email'   => ['type' => 'VARCHAR', 'constraint' => 190],
            'subject'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'body'       => ['type' => 'TEXT'],
            'attempts'   => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'sent_at'    => $dt,
            'last_error' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => $dt,
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('sent_at');
        $this->forge->createTable('mail_queue', true);

        $this->seedSla();
    }

    private function seedSla(): void
    {
        $now = date('Y-m-d H:i:s');

        // minutes for first response and resolution per priority
        $base = [
            'Urgent' => [30, 240],
            'High'   => [60, 480],
            'Medium' => [240, 1440],
            'Low'    => [480, 4320],
        ];
        $scale = [
            'Common Request'      => 1,
            'Special Request'     => 1.5,
            'Small-Scale Request' => 2,
            'Mid-Scale Request'   => 4,
            'Large-Scale Request' => 8,
        ];

        foreach ($scale as $category => $factor) {
            foreach ($base as $priority => [$response, $resolution]) {
                $this->db->table('sla_policies')->insert([
                    'category'           => $category,
                    'priority'           => $priority,
                    'response_minutes'   => $response,
                    'resolution_minutes' => (int) round($resolution * $factor),
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
            }
        }
    }

    public function down()
    {
        foreach (['mail_queue', 'services', 'canned_responses', 'ticket_approvals', 'ticket_feedback', 'audit_log', 'ticket_events', 'sla_policies'] as $table) {
            $this->forge->dropTable($table, true);
        }

        $this->forge->dropColumn('users', 'notify_email');
        $this->forge->dropColumn('ticket_messages', 'is_internal');
        $this->forge->dropColumn('department_roles', 'is_confidential');
        $this->forge->dropColumn('departments', 'is_confidential');
        $this->forge->dropColumn('tickets', ['requester_department', 'service_key', 'service_fields', 'is_confidential', 'approval_state', 'resolution_note', 'routing_reason']);
        $this->forge->dropColumn('ticket_meta', ['response_due_at', 'due_at', 'first_response_at', 'resolved_at', 'paused_at', 'sla_paused_minutes', 'reopened_count', 'sla_warned_at', 'needed_by', 'people_affected', 'work_stopped']);
    }
}
