<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTicketSearchIndex extends Migration
{
    public function up()
    {

        if (! $this->isMySql()) {
            return;
        }

        $this->db->query(
            'ALTER TABLE tickets ADD FULLTEXT INDEX ft_ticket_search (request_title, request_body, description)'
        );
    }

    public function down()
    {
        if (! $this->isMySql()) {
            return;
        }

        $this->db->query('ALTER TABLE tickets DROP INDEX ft_ticket_search');
    }

    private function isMySql(): bool
    {
        return str_contains(strtolower($this->db->DBDriver), 'mysql');
    }
}
