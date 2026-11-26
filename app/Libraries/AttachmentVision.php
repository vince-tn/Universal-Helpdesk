<?php

namespace App\Libraries;

use App\Models\TicketMessageModel;

// reads screenshots attached to a ticket with the image model and keeps the reading for the team and the classifier
class AttachmentVision
{
    public const AUTHOR = 'Screenshot reader';

    private const MAX_IMAGES = 4;

    private const MAX_BYTES = 5_242_880;

    private const PROMPT = 'You read screenshots attached to an internal IT and HR help desk ticket. '
        . 'In plain English and at most 6 short lines, state what the screenshot shows: the app or website, any error message copied word for word, '
        . 'error codes, and the step the person seems stuck on. Do not guess beyond what is visible. '
        . 'Never repeat passwords, one time codes, bank or card numbers, or ID numbers; write [hidden] instead.';

    // returns the combined reading, empty when there is nothing to read or it is not allowed
    public function readTicket(string $ticketId, string $html, bool $confidential): string
    {
        if ($confidential || ! OpenRouter::configured() || OpenRouter::visionModel() === '') {
            return '';
        }

        $files = $this->images($html);

        if ($files === []) {
            return '';
        }

        $db       = \Config\Database::connect();
        $readings = [];
        $fresh    = [];

        foreach (array_slice($files, 0, self::MAX_IMAGES) as $file) {
            $row = $db->table('attachment_readings')->where(['ticket_id' => $ticketId, 'file' => $file])->get()->getRowArray();

            if ($row !== null) {
                if ($row['status'] === 'done' && trim((string) $row['reading']) !== '') {
                    $readings[] = trim((string) $row['reading']);
                }
                continue;
            }

            [$ok, $text, $error] = $this->readOne($file);

            $db->table('attachment_readings')->insert([
                'ticket_id'  => $ticketId,
                'file'       => $file,
                'model'      => OpenRouter::visionModel(),
                'reading'    => $ok ? mb_substr($text, 0, 2000) : mb_substr($error, 0, 2000),
                'status'     => $ok ? 'done' : 'failed',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($ok && $text !== '') {
                $readings[] = $text;
                $fresh[]    = $text;
            } elseif (! $ok) {
                log_message('error', 'screenshot reading failed on ticket {id}: {e}', ['id' => $ticketId, 'e' => $error]);
            }
        }

        if ($fresh !== []) {
            (new TicketMessageModel())->post($ticketId, null, self::AUTHOR, 'ai', "What the attached screenshot shows:\n" . implode("\n\n", $fresh), TicketMessageModel::KIND_MESSAGE, null, false, true);
        }

        return implode("\n\n", $readings);
    }

    // returns [ok, text, error]
    private function readOne(string $file): array
    {
        $path = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'tickets' . DIRECTORY_SEPARATOR . $file;

        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            return [false, '', 'file missing or too large'];
        }

        $info = @getimagesize($path);
        $mime = $info['mime'] ?? '';

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return [false, '', 'not an image'];
        }

        $data = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));

        [$ok, $text, $error] = (new OpenRouter())->chat(OpenRouter::visionModel(), [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => self::PROMPT],
                ['type' => 'image_url', 'image_url' => ['url' => $data]],
            ],
        ]], 400, 60);

        return [$ok, TicketAssistant::clean($text), $error];
    }

    // file names of images uploaded through the app, in the order they appear
    public function images(string $html): array
    {
        preg_match_all('#/uploads/tickets/([a-f0-9]{32}\.(?:png|jpe?g|gif|webp))#i', $html, $m);

        return array_values(array_unique($m[1] ?? []));
    }
}
