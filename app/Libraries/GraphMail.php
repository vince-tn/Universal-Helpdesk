<?php

namespace App\Libraries;

use RuntimeException;

// microsoft graph mail calls with an app-only client credentials token
class GraphMail
{
    private const LOGIN = 'https://login.microsoftonline.com/';
    private const API   = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private string $tenant,
        private string $clientId,
        private string $secret
    ) {
    }

    public static function configured(): bool
    {
        foreach (['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET', 'MAIL_INTAKE'] as $key) {
            if (trim((string) (env($key) ?: '')) === '') {
                return false;
            }
        }

        return true;
    }

    public static function fromEnv(): self
    {
        return new self(trim((string) env('GRAPH_TENANT_ID')), trim((string) env('GRAPH_CLIENT_ID')), trim((string) env('GRAPH_CLIENT_SECRET')));
    }

    // unread inbox messages, oldest first
    public function unread(string $mailbox, int $top = 25): array
    {
        $url = self::API . '/users/' . rawurlencode($mailbox) . '/mailFolders/inbox/messages'
            . '?$filter=' . rawurlencode('isRead eq false')
            . '&$top=' . $top
            . '&$select=' . rawurlencode('id,subject,from,body,internetMessageHeaders,receivedDateTime');

        [$status, $body] = $this->call('GET', $url);

        if ($status !== 200) {
            throw new RuntimeException("graph list for {$mailbox} failed with {$status}: " . $this->error($body));
        }

        $messages = $body['value'] ?? [];
        usort($messages, static fn ($a, $b) => strcmp((string) ($a['receivedDateTime'] ?? ''), (string) ($b['receivedDateTime'] ?? '')));

        return $messages;
    }

    public function markRead(string $mailbox, string $messageId): bool
    {
        $url = self::API . '/users/' . rawurlencode($mailbox) . '/messages/' . rawurlencode($messageId);

        [$status, $body] = $this->call('PATCH', $url, ['isRead' => true]);

        if ($status !== 200) {
            log_message('error', 'graph mark read failed with {s}: {e}', ['s' => $status, 'e' => $this->error($body)]);
        }

        return $status === 200;
    }

    private function call(string $method, string $url, ?array $json = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Accept'        => 'application/json',
                'Prefer'        => 'IdType="ImmutableId"',
            ],
            'http_errors' => false,
            'timeout'     => 30,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $response = \Config\Services::curlrequest([], null, null, false)->request($method, $url, $options);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true) ?: []];
    }

    // token is cached until a minute before it expires
    private function token(): string
    {
        $key   = 'graph_token_' . md5($this->tenant . '|' . $this->clientId);
        $cache = cache();
        $token = $cache->get($key);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $response = \Config\Services::curlrequest([], null, null, false)->request(
            'POST',
            self::LOGIN . rawurlencode($this->tenant) . '/oauth2/v2.0/token',
            [
                'form_params' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->secret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                    'grant_type'    => 'client_credentials',
                ],
                'http_errors' => false,
                'timeout'     => 30,
            ]
        );

        $body  = json_decode((string) $response->getBody(), true) ?: [];
        $token = (string) ($body['access_token'] ?? '');

        if ($response->getStatusCode() !== 200 || $token === '') {
            throw new RuntimeException('graph token request failed with ' . $response->getStatusCode() . ': ' . (string) ($body['error_description'] ?? $body['error'] ?? 'no detail'));
        }

        $cache->save($key, $token, max(60, (int) ($body['expires_in'] ?? 3600) - 60));

        return $token;
    }

    private function error(array $body): string
    {
        return mb_substr((string) ($body['error']['message'] ?? $body['error']['code'] ?? 'no detail'), 0, 300);
    }
}
