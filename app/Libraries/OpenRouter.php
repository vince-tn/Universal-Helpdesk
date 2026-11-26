<?php

namespace App\Libraries;

// direct openrouter calls that always refuse providers which keep or train on our data
class OpenRouter
{
    private const API = 'https://openrouter.ai/api/v1';

    public static function key(): string
    {
        return trim((string) (env('OPENROUTER_API_KEY') ?: ''));
    }

    public static function configured(): bool
    {
        return self::key() !== '';
    }

    // the model used to read image attachments, saved setting first, then env
    public static function visionModel(): string
    {
        return trim((string) config(\Config\N8n::class)->imageModel);
    }

    public static function textModel(): string
    {
        return trim((string) (env('OPENROUTER_MODEL') ?: 'openrouter/auto'));
    }

    // routing rules sent with every request: no data collection, fall back across providers
    public static function provider(): array
    {
        return ['data_collection' => 'deny', 'allow_fallbacks' => true];
    }

    // returns [ok, text, error]
    public function chat(string $model, array $messages, int $maxTokens = 600, int $timeout = 90): array
    {
        if (! self::configured()) {
            return [false, '', 'OPENROUTER_API_KEY is not set'];
        }

        try {
            $response = \Config\Services::curlrequest([], null, null, false)->post(self::API . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . self::key(),
                    'X-Title'       => brand_name(),
                ],
                'json' => [
                    'model'       => $model,
                    'messages'    => $messages,
                    'max_tokens'  => $maxTokens,
                    'temperature' => 0.1,
                    'provider'    => self::provider(),
                ],
                'timeout'     => $timeout,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return [false, '', $e->getMessage()];
        }

        $body = json_decode((string) $response->getBody(), true) ?: [];

        if ($response->getStatusCode() !== 200) {
            return [false, '', (string) ($body['error']['message'] ?? ('http ' . $response->getStatusCode()))];
        }

        return [true, trim((string) ($body['choices'][0]['message']['content'] ?? '')), ''];
    }

    public function models(): array
    {
        try {
            $response = \Config\Services::curlrequest([], null, null, false)->get(self::API . '/models', ['timeout' => 30, 'http_errors' => false]);
        } catch (\Throwable $e) {
            return [];
        }

        return json_decode((string) $response->getBody(), true)['data'] ?? [];
    }

    // a model is listed as image capable only when its own metadata says it takes images
    public static function takesImages(array $model): bool
    {
        return in_array('image', (array) ($model['architecture']['input_modalities'] ?? []), true)
            && in_array('text', (array) ($model['architecture']['output_modalities'] ?? []), true);
    }

    // free endpoints may log and train on prompts, so they are flagged and never picked
    public static function mayTrain(array $model): bool
    {
        $pricing = $model['pricing'] ?? [];

        return str_ends_with((string) ($model['id'] ?? ''), ':free')
            || ((string) ($pricing['prompt'] ?? '') === '0' && (string) ($pricing['completion'] ?? '') === '0');
    }
}
