<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRoomReservations extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 80],
            'location'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'capacity'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 4],
            'equipment'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'needs_approval' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_active'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('rooms', true);

        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'room_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'title'            => ['type' => 'VARCHAR', 'constraint' => 150],
            'starts_at'        => ['type' => 'DATETIME'],
            'ends_at'          => ['type' => 'DATETIME'],
            'attendees'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 1],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'confirmed'],
            'recurrence_group' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'checked_in_at'    => ['type' => 'DATETIME', 'null' => true],
            'approved_by'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['room_id', 'starts_at']);
        $this->forge->addKey('user_id');
        $this->forge->addKey('recurrence_group');
        $this->forge->createTable('room_bookings', true);

        // seed example rooms only on an empty table
        if ($this->db->table('rooms')->countAllResults() > 0) {
            return;
        }

        $now   = date('Y-m-d H:i:s');
        $rooms = [
            ['Huddle Room', '2nd floor, near the pantry', 4, 'TV', 0],
            ['Training Room', '3rd floor, east wing', 12, 'TV, whiteboard', 0],
            ['Boardroom', '5th floor', 20, 'TV, conference phone, whiteboard', 1],
        ];

        foreach ($rooms as [$name, $location, $capacity, $equipment, $approval]) {
            $this->db->table('rooms')->insert([
                'name'           => $name,
                'location'       => $location,
                'capacity'       => $capacity,
                'equipment'      => $equipment,
                'needs_approval' => $approval,
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
    }

    public function down()
    {
        $this->forge->dropTable('room_bookings', true);
        $this->forge->dropTable('rooms', true);
    }
}
