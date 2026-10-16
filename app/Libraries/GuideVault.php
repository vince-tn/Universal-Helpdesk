<?php

namespace App\Libraries;

class GuideVault
{
    public const GLOBAL_FOLDER  = 'All departments';
    public const UNKNOWN_FOLDER = 'Unknown department';

    private const UNSAFE    = '/:*?"<>|.';
    private const SLUG_MAX  = 60;

    public static function folder(?string $departmentName): string
    {
        $name = trim((string) $departmentName);

        if ($name === '') {
            return self::GLOBAL_FOLDER;
        }

        $dashed = str_replace(str_split(self::UNSAFE . DIRECTORY_SEPARATOR), '-', $name);
        $clean  = trim((string) preg_replace('/-+/', '-', $dashed), ' -');

        return $clean === '' ? self::UNKNOWN_FOLDER : $clean;
    }

    public static function slug(string $title): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($title))), '-');

        return $slug === '' ? 'guide' : mb_substr($slug, 0, self::SLUG_MAX);
    }

    public static function fileName(array $article): string
    {
        return self::slug((string) ($article['title'] ?? '')) . '-' . (int) ($article['id'] ?? 0) . '.md';
    }

    public static function isGenerated(string $fileName): bool
    {
        return preg_match('/-[0-9]+[.]md$/', $fileName) === 1;
    }

    public static function note(array $article, string $department): string
    {
        $front = [
            'uid: ' . (int) ($article['id'] ?? 0),
            'title: ' . self::quote((string) ($article['title'] ?? '')),
            'department: ' . self::quote($department),
            'active: ' . ((int) ($article['is_active'] ?? 1) === 1 ? 'true' : 'false'),
            'updated: ' . self::quote((string) ($article['updated_at'] ?? $article['created_at'] ?? '')),
        ];

        foreach (self::keywords($article) as $i => $tag) {
            if ($i === 0) {
                $front[] = 'keywords:';
            }

            $front[] = '  - ' . self::quote($tag);
        }

        return "---\n" . implode("\n", $front) . "\n---\n\n"
            . '# ' . trim((string) ($article['title'] ?? '')) . "\n\n"
            . rtrim(self::lines((string) ($article['body'] ?? ''))) . "\n";
    }

    public static function lines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private static function keywords(array $article): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) ($article['keywords'] ?? ''))),
            static fn ($tag) => $tag !== ''
        ));
    }

    private static function quote(string $value): string
    {
        $flat = trim(str_replace(["\r", "\n", "\t"], ' ', $value));

        return "'" . str_replace("'", "''", $flat) . "'";
    }
}
