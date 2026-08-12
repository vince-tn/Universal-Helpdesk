<?php

namespace App\Libraries;

use App\Models\NotificationModel;
use App\Models\UserModel;

class Mentions
{
    public function parse(string $body, int $excludeUserId = 0): array
    {
        if (! str_contains($body, '@')) {
            return [];
        }

        preg_match_all('/@([\p{L}][\p{L}\p{N}._\'-]*(?:[ ][\p{L}][\p{L}\p{N}._\'-]*){0,3})/u', $body, $matches);

        $candidates = array_unique(array_map('trim', $matches[1] ?? []));

        if ($candidates === []) {
            return [];
        }

        $users = (new UserModel())->where('is_active', 1)->findAll();
        $hits  = [];

        foreach ($candidates as $candidate) {
            $best = null;

            foreach ($users as $user) {
                if ((int) $user['id'] === $excludeUserId) {
                    continue;
                }

                $name  = (string) $user['name'];
                $local = strtok((string) $user['email'], '@');

                if (strcasecmp($candidate, $name) === 0 || strcasecmp($candidate, (string) $local) === 0) {
                    $best = $user;
                    break;
                }

                if ($best === null && stripos($candidate, $name) === 0) {
                    $best = $user;
                }
            }

            if ($best !== null) {
                $hits[(int) $best['id']] = $best;
            }
        }

        return array_values($hits);
    }

    public function notify(string $body, array $sender, string $ticketId, string $ticketTitle): array
    {
        $mentioned = $this->parse($body, (int) ($sender['id'] ?? 0));

        if ($mentioned === []) {
            return [];
        }

        $notifications = new NotificationModel();
        $excerpt       = mb_substr(trim(preg_replace('/\s+/', ' ', $body) ?? $body), 0, 160);

        foreach ($mentioned as $user) {
            $notifications->push(
                (int) $user['id'],
                $ticketId,
                (string) ($sender['name'] ?? 'Someone'),
                'mentioned you on "' . $ticketTitle . '": ' . $excerpt
            );
        }

        return $mentioned;
    }

    public function render(string $escapedBody, array $names): string
    {
        foreach ($names as $name) {
            $escaped = esc($name);
            $escapedBody = str_replace('@' . $escaped, '<span class="mention">@' . $escaped . '</span>', $escapedBody);
        }

        return $escapedBody;
    }
}
