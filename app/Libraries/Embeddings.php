<?php

namespace App\Libraries;

class Embeddings
{
    private const TIMEOUT = 15;

    private string $url;
    private string $model;

    public function __construct(?string $url = null, ?string $model = null)
    {
        $this->url   = rtrim((string) ($url ?? (env('OLLAMA_URL') ?: 'http://ollama:11434')), '/');
        $this->model = trim((string) ($model ?? (env('EMBED_MODEL') ?: 'qwen3-embedding:0.6b')));
    }

    public function enabled(): bool
    {
        return $this->model !== '' && strtolower(trim((string) env('KNOWLEDGE_SEARCH'))) !== 'text';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function query(string $text): string
    {
        $text = trim(mb_substr($text, 0, 2000));

        return str_starts_with($this->model, 'qwen3-embedding')
            ? "Instruct: Given an employee's helpdesk request, retrieve the guide that tells them what to do\nQuery: " . $text
            : $text;
    }

    public static function document(array $article): string
    {
        return trim(implode("\n", array_filter([
            trim((string) ($article['title'] ?? '')),
            trim((string) ($article['keywords'] ?? '')),
            trim((string) ($article['body'] ?? '')),
        ], static fn ($part) => $part !== '')));
    }

    public function embed(array $texts): ?array
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return [];
        }

        try {
            $response = \Config\Services::curlrequest([], null, null, false)->post($this->url . '/api/embed', [
                'json'            => ['model' => $this->model, 'input' => $texts],
                'timeout'         => self::TIMEOUT,
                'connect_timeout' => 3,
                'http_errors'     => false,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Embedding request failed: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            log_message('error', 'Embedding model {model} answered HTTP {code}: {body}', [
                'model' => $this->model,
                'code'  => $response->getStatusCode(),
                'body'  => substr((string) $response->getBody(), 0, 300),
            ]);

            return null;
        }

        $vectors = json_decode((string) $response->getBody(), true)['embeddings'] ?? null;

        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            return null;
        }

        return array_map(static fn ($vector) => array_map('floatval', (array) $vector), $vectors);
    }

    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));

        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $na  = 0.0;
        $nb  = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }

        return ($na > 0.0 && $nb > 0.0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
