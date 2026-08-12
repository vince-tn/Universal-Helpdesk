<?php

namespace App\Commands;

use App\Libraries\Mailer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Access;
use Config\Email as EmailConfig;
use Config\N8n;

class Doctor extends BaseCommand
{
    protected $group       = 'Helpdesk';
    protected $name        = 'helpdesk:doctor';
    protected $description = 'Checks the database, n8n, both webhooks and the local model, and says what to do about anything that is down.';
    protected $usage       = 'helpdesk:doctor';

    private int $failures = 0;

    public function run(array $params)
    {
        $config = new N8n();

        CLI::write('Chikiting System - health check', 'yellow');
        CLI::newLine();

        $this->database();
        $this->n8n($config);
        $this->webhook('submit-ticket', $config->webhookUrl);
        $this->webhook('ticket-chat', $config->chatWebhookUrl);
        $this->ollama();
        $this->cloud($config);
        $this->mail();
        $this->registration();
        $this->routing();
        $this->clock();

        CLI::newLine();

        if ($this->failures === 0) {
            CLI::write('Everything checks out. Open http://localhost:8081 and submit a ticket.', 'green');

            return;
        }

        CLI::write($this->failures . ' check(s) need attention - see the lines marked FAIL above.', 'red');
    }

    private function database(): void
    {
        try {
            $db     = db_connect();
            $users  = $db->table('users')->countAllResults();
            $ticket = $db->table('tickets')->countAllResults();

            $this->ok(sprintf(
                'database    %s on %s - %d user(s), %d ticket(s)',
                $db->getDatabase(),
                $db->DBDriver,
                $users,
                $ticket
            ));
        } catch (\Throwable $e) {
            $this->fail('database    ' . $e->getMessage(), 'is the db container healthy? docker compose ps');
        }
    }

    private function n8n(N8n $config): void
    {
        $body = $this->get(rtrim($config->baseUrl, '/') . '/healthz', 5);

        if ($body !== null && str_contains($body, 'ok')) {
            $this->ok('n8n         reachable at ' . $config->baseUrl);

            return;
        }

        $this->fail('n8n         no answer at ' . $config->baseUrl, 'docker compose logs n8n');
    }

    private function webhook(string $label, string $url): void
    {
        try {
            $response = \Config\Services::curlrequest([], null, null, false)->post($url, [
                'json'            => ['probe' => true],
                'timeout'         => 6,
                'connect_timeout' => 5,
                'http_errors'     => false,
            ]);

            $code = $response->getStatusCode();

            if ($code === 404) {
                $this->fail(
                    'webhook     ' . $label . ' is not registered',
                    'the workflow is not published: docker compose restart n8n'
                );

                return;
            }

            $this->ok('webhook     ' . $label . ' answered HTTP ' . $code);
        } catch (\Throwable $e) {

            if (stripos($e->getMessage(), 'refused') !== false) {
                $this->fail('webhook     ' . $label . ' - connection refused', 'docker compose logs n8n');

                return;
            }

            $this->ok('webhook     ' . $label . ' is registered (still working on the probe)');
        }
    }

    private function ollama(): void
    {
        $url   = rtrim((string) (env('OLLAMA_URL') ?: 'http://ollama:11434'), '/');
        $model = (string) (env('OLLAMA_MODEL') ?: 'qwen2.5:3b');

        $body = $this->get($url . '/api/tags', 5);

        if ($body === null) {
            $this->fail('ollama      no answer at ' . $url, 'docker compose logs ollama');

            return;
        }

        if (str_contains($body, '"' . $model . '"')) {
            $this->ok('ollama      ' . $model . ' is on disk');

            return;
        }

        $this->fail(
            'ollama      ' . $model . ' has not been pulled yet',
            'docker compose logs ollama-init - a first pull is a couple of GB'
        );
    }

    private function cloud(N8n $config): void
    {
        if ($config->cloudEnabled()) {
            $this->ok('cloud model enabled - big requests and chat use Gemini');

            return;
        }

        CLI::write('  --   cloud model off - running local-only. Set GEMINI_API_KEY in .env to enable.', 'dark_gray');
    }

    private function mail(): void
    {
        $config = config(EmailConfig::class);

        if (! (new Mailer())->isConfigured()) {
            $this->fail('mail        no SMTP host configured', 'set MAIL_HOST, or leave it unset to use the mailpit container');

            return;
        }

        $socket = @fsockopen($config->SMTPHost, $config->SMTPPort, $errno, $errstr, 4);

        if ($socket === false) {
            $this->fail(
                'mail        ' . $config->SMTPHost . ':' . $config->SMTPPort . ' - ' . ($errstr ?: 'no answer'),
                $config->SMTPHost === 'mailpit'
                    ? 'docker compose up -d mailpit'
                    : 'check MAIL_HOST / MAIL_PORT, and that the relay allows this host'
            );

            return;
        }

        fclose($socket);

        $this->ok(sprintf(
            'mail        %s:%d accepting connections%s',
            $config->SMTPHost,
            $config->SMTPPort,
            $config->SMTPHost === 'mailpit' ? ' - read the codes at http://localhost:8025' : ''
        ));
    }

    private function registration(): void
    {
        $access = config(Access::class);

        if (! $access->domainCheckEnabled()) {
            $this->fail(
                'signup      the domain allowlist is EMPTY - anyone may register',
                'set ALLOWED_EMAIL_DOMAINS in .env. Fine to ignore on a local demo'
            );

            return;
        }

        $extra = $access->allowedEmails === []
            ? ''
            : sprintf(' (+%d individual address%s)', count($access->allowedEmails), count($access->allowedEmails) === 1 ? '' : 'es');

        $this->ok(sprintf(
            'signup      %d domain(s) allowed: %s%s',
            count($access->allowedDomains),
            implode(', ', $access->allowedDomains),
            $extra
        ));
    }

    private function routing(): void
    {
        try {
            $rows = db_connect()->table('tickets')

                ->select('responsible_department, COUNT(*) AS n', false)
                ->where('responsible_department IS NOT NULL')
                ->where('responsible_department !=', '')
                ->groupBy('responsible_department')
                ->get()
                ->getResultArray();
        } catch (\Throwable $e) {
            return;
        }

        $orphans = [];

        foreach ($rows as $row) {
            $resolved = resolve_base_department((string) $row['responsible_department']);

            if (! in_array($resolved, departments(), true)) {
                $orphans[] = $row['responsible_department'] . ' (' . $row['n'] . ')';
            }
        }

        if ($orphans === []) {
            $this->ok('routing     every ticket maps to a department an agent can hold');

            return;
        }

        $this->fail(
            'routing     no agent can be scoped to: ' . implode(', ', $orphans),
            'add an alias in resolve_base_department() in app/Common.php'
        );
    }

    private function clock(): void
    {
        $app       = date_default_timezone_get();
        $container = trim((string) (env('TZ') ?: ''));

        if ($container !== '' && $container !== $app) {
            $this->fail(
                sprintf('clock       PHP is on %s but the container is on %s', $app, $container),
                'set app_appTimezone to match TZ on the app service in docker-compose.yml'
            );

            return;
        }

        $this->ok(sprintf('clock       %s - %s', $app, date('D j M Y, g:i A')));
    }

    private function get(string $url, int $timeout): ?string
    {
        try {
            $response = \Config\Services::curlrequest([], null, null, false)->get($url, [
                'timeout'         => $timeout,
                'connect_timeout' => 3,
                'http_errors'     => false,
            ]);

            return $response->getStatusCode() === 200 ? (string) $response->getBody() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function ok(string $message): void
    {
        CLI::write('  OK   ' . $message, 'green');
    }

    private function fail(string $message, string $fix): void
    {
        $this->failures++;
        CLI::write('  FAIL ' . $message, 'red');
        CLI::write('       -> ' . $fix, 'dark_gray');
    }
}
