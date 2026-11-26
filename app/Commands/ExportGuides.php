<?php

namespace App\Commands;

use App\Libraries\GuideVault;
use App\Models\DepartmentModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ExportGuides extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:export-guides';
    protected $description = 'Writes every knowledge article out as Markdown, one folder per department, for reading in Obsidian or any editor.';
    protected $usage       = 'helpdesk:export-guides [--out <dir>] [--quiet]';
    protected $options     = [
        '--out'   => 'Where to write the vault. Defaults to writable/guides.',
        '--quiet' => 'Only print the summary line.',
    ];

    private const README = <<<'TEXT'
    # Chikiting System guides

    Every file here is written out of the helpdesk database by
    `php spark helpdesk:export-guides`. It is a read-only copy for browsing.

    **Editing a file here changes nothing.** The next export overwrites it.
    Guides are edited in the app, under Departments, where the department
    limits, the revision history and the live "test it" preview are.

    Each note's `uid` is that article's id in the database.
    TEXT;

    public function run(array $params)
    {
        $out   = (string) ($params['out'] ?? CLI::getOption('out') ?: WRITEPATH . 'guides');
        $quiet = array_key_exists('quiet', $params) || CLI::getOption('quiet');
        $out   = rtrim(strtr($out, DIRECTORY_SEPARATOR, '/'), '/');

        if (! $this->ensure($out)) {
            return;
        }

        $names = [];
        foreach ((new DepartmentModel())->allOrdered() as $department) {
            $names[(int) $department['id']] = (string) $department['name'];
        }

        $articles = db_connect()->table('knowledge_articles')
            ->select('id, department_id, title, keywords, body, is_active, updated_at, created_at')
            ->orderBy('department_id', 'ASC')
            ->orderBy('title', 'ASC')
            ->get()
            ->getResultArray();

        $written = [];

        foreach ($articles as $article) {
            $department = $article['department_id'] === null
                ? GuideVault::GLOBAL_FOLDER
                : ($names[(int) $article['department_id']] ?? GuideVault::UNKNOWN_FOLDER);

            $directory = $out . '/' . GuideVault::folder($department);

            if (! $this->ensure($directory)) {
                return;
            }

            $path = $directory . '/' . GuideVault::fileName($article);

            file_put_contents($path, GuideVault::note($article, $department));
            $written[$path] = true;

            if (! $quiet) {
                CLI::write('  ' . str_replace($out . '/', '', $path), 'dark_gray');
            }
        }

        $removed = $this->prune($out, $written);

        file_put_contents($out . '/README.md', $this->readme());

        CLI::write(sprintf(
            '%d guide(s) written to %s%s',
            count($written),
            $out,
            $removed === 0 ? '' : ', ' . $removed . ' stale file(s) removed'
        ), 'green');
    }

    private function ensure(string $directory): bool
    {
        if (is_dir($directory) || (@mkdir($directory, 0775, true) || is_dir($directory))) {
            return true;
        }

        CLI::error('Could not create ' . $directory);

        return false;
    }

    private function readme(): string
    {
        return GuideVault::lines(self::README) . "\n\nLast written " . date('Y-m-d H:i:s') . ".\n";
    }

    private function prune(string $out, array $keep): int
    {
        $removed = 0;

        foreach ($this->markdownIn($out) as $path) {
            if (isset($keep[$path]) || basename($path) === 'README.md') {
                continue;
            }

            if (GuideVault::isGenerated(basename($path)) && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function markdownIn(string $directory): array
    {
        $found = [];

        foreach ((array) glob($directory . '/*') as $entry) {
            $entry = strtr((string) $entry, DIRECTORY_SEPARATOR, '/');

            if (is_dir($entry)) {
                $found = array_merge($found, $this->markdownIn($entry));
            } elseif (str_ends_with($entry, '.md')) {
                $found[] = $entry;
            }
        }

        return $found;
    }
}
