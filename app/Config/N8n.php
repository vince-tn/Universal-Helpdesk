<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class N8n extends BaseConfig
{

    public string $baseUrl = 'http://n8n:5678';

    public string $webhookUrl = '';

    public string $chatWebhookUrl = '';

    public int $timeout = 180;

    public bool $assistantEnabled = true;

    public function __construct()
    {
        parent::__construct();

        $this->baseUrl = rtrim((string) (env('N8N_BASE_URL') ?: $this->baseUrl), '/');

        $this->webhookUrl = (string) (env('N8N_WEBHOOK_URL')
            ?: $this->baseUrl . '/webhook/submit-ticket');

        $this->chatWebhookUrl = (string) (env('N8N_CHAT_WEBHOOK_URL')
            ?: $this->baseUrl . '/webhook/ticket-chat');

        $this->timeout = max(15, (int) (env('AI_TIMEOUT') ?: $this->timeout));

        $this->assistantEnabled = $this->truthy('AI_ASSISTANT', true);
    }

    public function cloudEnabled(): bool
    {
        $mode = strtolower(trim((string) (env('AI_ESCALATION') ?: 'auto')));

        return match ($mode) {
            'on', 'true', '1', 'yes'  => true,
            'off', 'false', '0', 'no' => false,
            default                   => $this->cloudKey() !== '',
        };
    }

    // openrouter when picked, gemini otherwise
    public function cloudProvider(): string
    {
        return strtolower(trim((string) (env('AI_CLOUD_PROVIDER') ?: 'gemini'))) === 'openrouter' ? 'openrouter' : 'gemini';
    }

    private function cloudKey(): string
    {
        $key = $this->cloudProvider() === 'openrouter' ? 'OPENROUTER_API_KEY' : 'GEMINI_API_KEY';

        return trim((string) (env($key) ?: ''));
    }

    // confidential teams never leave the box when this is on
    public function confidentialLocalOnly(): bool
    {
        return $this->truthy('CONFIDENTIAL_LOCAL_ONLY', true);
    }

    private function truthy(string $key, bool $default): bool
    {
        $raw = env($key);

        if ($raw === null || $raw === false || $raw === '') {
            return $default;
        }

        return ! in_array(strtolower(trim((string) $raw)), ['0', 'off', 'false', 'no'], true);
    }
}
