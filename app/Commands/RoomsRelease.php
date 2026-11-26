<?php

namespace App\Commands;

use App\Models\RoomBookingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class RoomsRelease extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'rooms:release';
    protected $description = 'Releases room bookings nobody checked into within 15 minutes of the start.';
    protected $usage       = 'rooms:release';

    public function run(array $params)
    {
        $released = (new RoomBookingModel())->releaseDue();

        CLI::write($released === 0 ? 'No bookings to release.' : 'Released ' . $released . ' booking(s).', 'green');
    }
}
