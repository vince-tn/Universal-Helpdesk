<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOrgAndNotifications extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 80],
            'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'sort_order' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('departments');

        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'department_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 80],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['department_id', 'name']);
        $this->forge->createTable('department_roles');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'ticket_id'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'actor_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'type'       => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'mention'],
            'body'       => ['type' => 'VARCHAR', 'constraint' => 500],
            'is_read'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'is_read']);
        $this->forge->createTable('notifications');

        $this->forge->addColumn('users', [
            'department_role_id' => [
                'type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'department',
            ],
        ]);

        $now  = date('Y-m-d H:i:s');
        $seed = [
            'IT'          => ['Helpdesk', 'Systems', 'Network'],
            'HR'          => ['Recruitment', 'Payroll', 'Employee Relations'],
            'TQA'         => ['Training', 'Quality Assurance'],
            'Reports/WFM' => ['Reporting', 'Workforce Planning'],
            'Technical'   => ['Web', 'Marketing', 'Salesforce/Tally', 'SQL'],
            'Compliance'  => ['Regulatory', 'Audit'],
            'Operations'  => ['Support', 'Service Delivery'],
        ];

        $order = 0;

        foreach ($seed as $department => $roles) {
            $this->db->table('departments')->insert([
                'name'       => $department,
                'is_active'  => 1,
                'sort_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $departmentId = (int) $this->db->insertID();

            foreach ($roles as $role) {
                $this->db->table('department_roles')->insert([
                    'department_id' => $departmentId,
                    'name'          => $role,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'department_role_id');
        $this->forge->dropTable('notifications', true);
        $this->forge->dropTable('department_roles', true);
        $this->forge->dropTable('departments', true);
    }
}
