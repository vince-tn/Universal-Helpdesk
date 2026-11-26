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

    public string $openRouterUrl = 'https://openrouter.ai/api/v1';

    public string $imageModel = '@preset/image-processing';

    public array $imageFallbacks = ['inclusionai/ling-3.2-flash:free', 'google/gemma-4-26b-a4b-it:free'];

    public int $imageTimeout = 45;

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

        $this->imageModel = trim((string) (env('OPENROUTER_IMAGE_MODEL') ?: $this->imageModel));

        $fallbacks = env('OPENROUTER_IMAGE_FALLBACKS');

        if (is_string($fallbacks) && trim($fallbacks) !== '') {
            $this->imageFallbacks = array_values(array_filter(array_map('trim', explode(',', $fallbacks))));
        }
    }

    public function imagesEnabled(): bool
    {
        return self::imagesFor(
            $this->truthy('AI_IMAGES', true),
            $this->imageModel,
            $this->cloudEnabled(),
            $this->cloudProvider()
        );
    }

    public static function imagesFor(bool $switchedOn, string $model, bool $cloudEnabled, string $provider): bool
    {
        return $switchedOn
            && trim($model) !== ''
            && $cloudEnabled
            && $provider === self::PROVIDER_OPENROUTER;
    }

    public const PROVIDER_NONE       = 'none';
    public const PROVIDER_GEMINI     = 'gemini';
    public const PROVIDER_OPENROUTER = 'openrouter';

    public function cloudEnabled(): bool
    {
        $mode = strtolower(trim((string) (env('AI_ESCALATION') ?: 'auto')));

        return match ($mode) {
            'on', 'true', '1', 'yes'  => $this->cloudProvider() !== self::PROVIDER_NONE,
            'off', 'false', '0', 'no' => false,
            default                   => $this->cloudProvider() !== self::PROVIDER_NONE,
        };
    }

    public function cloudProvider(): string
    {
        return self::providerFor(
            (string) (env('AI_CLOUD_PROVIDER') ?: ''),
            (string) (env('OPENROUTER_API_KEY') ?: ''),
            (string) (env('GEMINI_API_KEY') ?: '')
        );
    }

    public static function providerFor(string $named, string $openRouterKey, string $geminiKey): string
    {
        $hasOpenRouter = trim($openRouterKey) !== '';
        $hasGemini     = trim($geminiKey) !== '';

        return match (strtolower(trim($named))) {
            self::PROVIDER_OPENROUTER => $hasOpenRouter ? self::PROVIDER_OPENROUTER : self::PROVIDER_NONE,
            self::PROVIDER_GEMINI     => $hasGemini ? self::PROVIDER_GEMINI : self::PROVIDER_NONE,
            self::PROVIDER_NONE       => self::PROVIDER_NONE,
            default                   => match (true) {
                $hasOpenRouter => self::PROVIDER_OPENROUTER,
                $hasGemini     => self::PROVIDER_GEMINI,
                default        => self::PROVIDER_NONE,
            },
        };
    }

    public function cloudLabel(): string
    {
        return match ($this->cloudProvider()) {
            self::PROVIDER_OPENROUTER => 'OpenRouter',
            self::PROVIDER_GEMINI     => 'Gemini',
            default                   => 'None',
        };
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
