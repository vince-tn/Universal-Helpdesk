<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

// lays out departments and catalogue on a fresh branded copy only, never on live ticket data
class SeedServiceDeskStructure extends Migration
{
    public function up()
    {
        $brand = strtolower(trim((string) (env('BRAND') ?: 'chikiting')));

        if ($brand === 'chikiting' || $this->db->table('tickets')->countAllResults() > 0) {
            return;
        }

        $seeder = \Config\Database::seeder();
        $seeder->setSilent(true);
        $seeder->call('SampleKnowledgeSeeder');
        $seeder->call('ServiceDeskSeeder');
    }

    public function down()
    {
    }
}
