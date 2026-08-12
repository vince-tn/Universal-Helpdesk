<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ImportSqlite extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:import-sqlite';
    protected $description = 'Copies users, tickets, meta and conversations out of the old writable/database.db into the configured database, once.';
    protected $usage       = 'helpdesk:import-sqlite';

    public function run(array $params)
    {
        $db = db_connect();

        if ($db->DBDriver === 'SQLite3') {
            CLI::write('[import-sqlite] still on SQLite - nothing to import.', 'dark_gray');

            return;
        }

        $file = WRITEPATH . 'database.db';

        if (! is_file($file)) {
            CLI::write('[import-sqlite] no writable/database.db - nothing to import.', 'dark_gray');

            return;
        }

        if (! class_exists(\SQLite3::class)) {
            CLI::write('[import-sqlite] the sqlite3 extension is not loaded - skipping.', 'yellow');

            return;
        }

        if ($db->table('tickets')->countAllResults() > 0) {
            CLI::write('[import-sqlite] this database already has tickets - leaving it alone.', 'dark_gray');

            return;
        }

        $src = new \SQLite3($file, SQLITE3_OPEN_READONLY);
        $src->busyTimeout(2000);

        try {
            $userMap = $this->importUsers($db, $src);
            $tickets = $this->importTickets($db, $src);
            $meta    = $this->importMeta($db, $src, $userMap);
            $notes   = $this->importMessages($db, $src, $userMap);
        } finally {
            $src->close();
        }

        CLI::write(sprintf(
            '[import-sqlite] carried over %d user(s), %d ticket(s), %d meta row(s), %d message(s).',
            count($userMap),
            $tickets,
            $meta,
            $notes
        ), 'green');
    }

    private function importUsers($db, \SQLite3 $src): array
    {
        $map = [];

        foreach ($this->rows($src, 'users') as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            $existing = $db->table('users')->where('email', $email)->get()->getRowArray();

            if ($existing !== null) {
                $map[(int) $row['id']] = (int) $existing['id'];

                continue;
            }

            $insert = $this->shared($db, 'users', $row);
            unset($insert['id']);
            $insert['email'] = $email;

            $db->table('users')->insert($insert);
            $map[(int) $row['id']] = (int) $db->insertID();
        }

        return $map;
    }

    private function importTickets($db, \SQLite3 $src): int
    {
        $count = 0;

        foreach ($this->rows($src, 'tickets') as $row) {
            $db->table('tickets')->insert($this->shared($db, 'tickets', $row));
            $count++;
        }

        return $count;
    }

    private function importMeta($db, \SQLite3 $src, array $userMap): int
    {
        $count = 0;

        foreach ($this->rows($src, 'ticket_meta') as $row) {
            $insert = $this->shared($db, 'ticket_meta', $row);
            unset($insert['id']);

            $insert['assigned_to'] = $this->remap($row['assigned_to'] ?? null, $userMap);

            $db->table('ticket_meta')->insert($insert);
            $count++;
        }

        return $count;
    }

    private function importMessages($db, \SQLite3 $src, array $userMap): int
    {
        $count = 0;

        foreach ($this->rows($src, 'ticket_messages') as $row) {
            $insert = $this->shared($db, 'ticket_messages', $row);
            unset($insert['id']);

            $insert['user_id'] = $this->remap($row['user_id'] ?? null, $userMap);

            $db->table('ticket_messages')->insert($insert);
            $count++;
        }

        return $count;
    }

    private function remap($oldId, array $userMap): ?int
    {
        if ($oldId === null || $oldId === '') {
            return null;
        }

        return $userMap[(int) $oldId] ?? null;
    }

    private function shared($db, string $table, array $row): array
    {
        $columns = $db->getFieldNames($table);

        return array_intersect_key($row, array_flip($columns));
    }

    private function rows(\SQLite3 $src, string $table): array
    {
        $exists = $src->querySingle(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $src->escapeString($table) . "'"
        );

        if ($exists === null) {
            return [];
        }

        $result = $src->query('SELECT * FROM ' . $table . ' ORDER BY id ASC');

        if ($result === false) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }
}
