<?php

namespace App\Libraries;

use DateTimeZone;

class RoomInvite
{
    // builds an ics calendar with one event per booking
    public static function ics(array $bookings): string
    {
        $host  = parse_url((string) config('App')->baseURL, PHP_URL_HOST) ?: 'helpdesk.local';
        $stamp = gmdate('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Chikiting System//Room Bookings//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($bookings as $b) {
            $where = trim((string) ($b['room_name'] ?? 'Room') . ((string) ($b['room_location'] ?? '') !== '' ? ', ' . $b['room_location'] : ''));

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:room-booking-' . (int) $b['id'] . '@' . $host;
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . self::utc((string) $b['starts_at']);
            $lines[] = 'DTEND:' . self::utc((string) $b['ends_at']);
            $lines[] = 'SUMMARY:' . self::escape((string) $b['title']);
            $lines[] = 'LOCATION:' . self::escape($where);
            $lines[] = 'DESCRIPTION:' . self::escape('Room booking in ' . $where . '. Please check in within 15 minutes of the start or the room is released.');
            $lines[] = 'STATUS:' . (($b['status'] ?? '') === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE');
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function utc(string $local): string
    {
        $dt = RoomTime::parse($local);

        return $dt === null ? $local : $dt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $text);
    }

    // folds long lines at 75 octets as rfc 5545 asks
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out   = '';
        $limit = 75;

        while (strlen($line) > $limit) {
            $cut = $limit;

            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }

            $out .= substr($line, 0, $cut) . "\r\n ";
            $line  = substr($line, $cut);
            $limit = 74;
        }

        return $out . $line;
    }
}
