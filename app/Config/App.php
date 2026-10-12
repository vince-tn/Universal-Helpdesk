<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class App extends BaseConfig
{

    public string $baseURL = 'http://localhost:8080/';

    public array $allowedHostnames = [];

    public string $indexPage = 'index.php';

    public string $uriProtocol = 'REQUEST_URI';

    public string $permittedURIChars = 'a-z 0-9~%.:_\-';

    public string $defaultLocale = 'en';

    public bool $negotiateLocale = false;

    public array $supportedLocales = ['en'];

    public string $appTimezone = 'UTC';

    public string $charset = 'UTF-8';

    public bool $forceGlobalSecureRequests = false;

    public array $proxyIPs = [];

    public bool $CSPEnabled = false;

    /**
     * The office reaches the app at baseURL (APP_URL). People outside come in
     * through a Cloudflare tunnel at an HTTPS address that baseURL cannot know
     * in advance - a free quick tunnel gets a new random *.trycloudflare.com
     * name every time it starts. PUBLIC_HOST_SUFFIXES lists the endings the
     * app accepts as its own (comma separated, e.g. ".trycloudflare.com"); a
     * request for such a host gets its links and redirects built on
     * https://that-host/ instead of baseURL, so nobody is sent to an office
     * address they cannot reach.
     *
     * This reads the request, so Config\Optimize::$configCacheEnabled must stay
     * false - a cached App would hand one visitor's host to everyone.
     */
    public function __construct()
    {
        parent::__construct();

        $host     = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''), 2)[0]);
        $suffixes = array_filter(array_map(
            static fn ($s) => '.' . ltrim(strtolower(trim($s)), '.'),
            explode(',', (string) (env('PUBLIC_HOST_SUFFIXES') ?: ''))
        ), static fn ($s) => $s !== '.');

        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return;
        }

        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                $this->baseURL = 'https://' . $host . '/';

                return;
            }
        }
    }
}
