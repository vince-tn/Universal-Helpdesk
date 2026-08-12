<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketMetaModel extends Model
{
    protected $table         = 'ticket_meta';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $allowedFields = ['ticket_id', 'priority', 'due_date', 'assigned_to'];

    public function forTickets(array $ticketIds): array
    {
        if (empty($ticketIds)) {
            return [];
        }

        $rows = $this->whereIn('ticket_id', $ticketIds)->findAll();

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row['ticket_id']] = $row;
        }

        return $keyed;
    }

    public function firstOrCreate(string $ticketId): array
    {
        $existing = $this->where('ticket_id', $ticketId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->insert(['ticket_id' => $ticketId, 'priority' => 'Medium']);

        return $this->where('ticket_id', $ticketId)->first();
    }

    public function updateForTicket(string $ticketId, array $data): bool
    {
        $this->firstOrCreate($ticketId);

        return $this->where('ticket_id', $ticketId)->set($data)->update();
    }
}
