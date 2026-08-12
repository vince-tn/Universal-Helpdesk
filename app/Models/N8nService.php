<?php

namespace App\Models;

use Config\N8n;

class N8nService
{
    protected N8n $config;
    protected \CodeIgniter\HTTP\CURLRequest $client;

    public function __construct()
    {
        $this->config = new N8n();

        $this->client = \Config\Services::curlrequest([], null, null, false);
    }

    public function classify(array $submission, string $priorContext = ''): ?array
    {
        $item = $this->post($this->config->webhookUrl, $submission + [

            'allow_escalation' => $this->config->cloudEnabled() ? 'Yes' : 'No',
            'prior_context'    => $priorContext,
        ]);

        if ($item === null) {
            return null;
        }

        if (! isset($item['output']) || ! is_array($item['output'])) {
            log_message('error', 'n8n classify response had no output object');

            return null;
        }

        return [
            'output'    => $item['output'],
            'ai_source' => (string) ($item['ai_source'] ?? 'Local'),
        ];
    }

    public function chat(array $payload): ?array
    {
        $payload['prefer_cloud'] = $this->config->cloudEnabled() ? 'Yes' : 'No';

        $item = $this->post($this->config->chatWebhookUrl, $payload);

        if ($item === null) {
            return null;
        }

        $reply = trim((string) ($item['reply'] ?? ''));

        if ($reply === '') {
            log_message('error', 'n8n chat response had no reply');

            return null;
        }

        return [
            'reply'            => $reply,
            'intent'           => (string) ($item['intent'] ?? 'NEW_INFO'),
            'resolution_state' => (string) ($item['resolution_state'] ?? 'OPEN'),
            'can_self_serve'   => (string) ($item['can_self_serve'] ?? 'No'),

            'confidence'       => (string) ($item['confidence'] ?? 'medium'),
            'ai_source'        => (string) ($item['ai_source'] ?? 'Local'),
            'actions'          => is_array($item['actions'] ?? null) ? $item['actions'] : [],
        ];
    }

    private function post(string $url, array $payload): ?array
    {
        try {
            $response = $this->client->post($url, [
                'json'            => $payload,
                'timeout'         => $this->config->timeout,
                'connect_timeout' => 10,
                'http_errors'     => false,
            ]);
        } catch (\Throwable $e) {

            log_message('error', 'n8n request to {url} failed: {message}', [
                'url'     => $url,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            log_message('error', 'n8n {url} returned HTTP {code}: {body}', [
                'url'  => $url,
                'code' => $response->getStatusCode(),
                'body' => substr((string) $response->getBody(), 0, 500),
            ]);

            return null;
        }

        $body = json_decode((string) $response->getBody(), true);

        $item = match (true) {
            ! is_array($body)      => null,
            array_is_list($body)   => is_array($body[0] ?? null) ? $body[0] : null,
            default                => $body,
        };

        if ($item === null) {
            log_message('error', 'n8n {url} returned an unreadable body: {body}', [
                'url'  => $url,
                'body' => substr((string) $response->getBody(), 0, 500),
            ]);
        }

        return $item;
    }
}
