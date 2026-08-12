<?php

use CodeIgniter\Boot;
use Config\Paths;

require __DIR__ . '/app/Config/Paths.php';

// Path to the front controller
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

class preload
{
    /**
     * @var array Paths to preload.
     */
    private array $paths = [
        [
            'include' => __DIR__ . '/vendor/codeigniter4/framework/system', // Change this path if using manual installation
            'exclude' => [
                // Not needed if you don't use them.
                '/system/Database/OCI8/',
                '/system/Database/Postgre/',
                '/system/Database/SQLite3/',
                '/system/Database/SQLSRV/',
                // Not needed for web apps.
                '/system/Database/Seeder.php',
                '/system/Test/',
                '/system/CLI/',
                '/system/Commands/',
                '/system/Publisher/',
                '/system/ComposerScripts.php',
                // Not Class/Function files.
                '/system/Config/Routes.php',
                '/system/Language/',
                '/system/bootstrap.php',
                '/system/util_bootstrap.php',
                '/system/rewrite.php',
                '/Views/',
                // Errors occur.
                '/system/ThirdParty/',
            ],
        ],
    ];

    public function __construct()
    {
        $this->loadAutoloader();
    }

    private function loadAutoloader(): void
    {
        $paths = new Paths();
        require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'Boot.php';

        Boot::preload($paths);
    }

    /**
     * Load PHP files.
     */
    public function load(): void
    {
        foreach ($this->paths as $path) {
            $directory = new RecursiveDirectoryIterator($path['include']);
            $fullTree  = new RecursiveIteratorIterator($directory);
            $phpFiles  = new RegexIterator(
                $fullTree,
                '/.+((?<!Test)+\.php$)/i',
                RecursiveRegexIterator::GET_MATCH,
            );

            foreach ($phpFiles as $key => $file) {
                foreach ($path['exclude'] as $exclude) {
                    if (str_contains($file[0], $exclude)) {
                        continue 2;
                    }
                }

                require_once $file[0];
                // For debugging (to inspect which files are included).
                // Preload scripts must not generate output.
                // echo 'Loaded: ' . $file[0] . "\n";
            }
        }
    }
}

(new preload())->load();
