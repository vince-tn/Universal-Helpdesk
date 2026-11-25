<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;

class RoomTime
{
    public const ZONE       = 'Asia/Manila';
    public const ZONE_LABEL = 'PHT';
    public const DAY_START  = '07:00';
    public const DAY_END    = '21:00';
    public const SLOT_MIN   = 30;
    public const DB_FORMAT  = 'Y-m-d H:i:s';

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::ZONE);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    public static function nowDb(): string
    {
        return self::now()->format(self::DB_FORMAT);
    }

    // parses a stored or typed local time in the room zone
    public static function parse(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, self::zone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    // a valid yyyy-mm-dd or today
    public static function day(?string $ymd): DateTimeImmutable
    {
        $ymd = (string) $ymd;

        if (is_ymd_date($ymd)) {
            return new DateTimeImmutable($ymd . ' 00:00:00', self::zone());
        }

        return self::now()->setTime(0, 0);
    }

    // slot start times like 07:00 to 20:30
    public static function slots(): array
    {
        $slots = [];
        $at    = new DateTimeImmutable('2000-01-01 ' . self::DAY_START, self::zone());
        $end   = new DateTimeImmutable('2000-01-01 ' . self::DAY_END, self::zone());

        while ($at < $end) {
            $slots[] = $at->format('H:i');
            $at      = $at->modify('+' . self::SLOT_MIN . ' minutes');
        }

        return $slots;
    }

    // valid end times like 07:30 to 21:00
    public static function endTimes(): array
    {
        $ends = array_slice(self::slots(), 1);
        $ends[] = self::DAY_END;

        return $ends;
    }

    public static function date(string|DateTimeImmutable $value): string
    {
        $dt = is_string($value) ? self::parse($value) : $value;

        return $dt === null ? '' : $dt->format('j M Y');
    }

    public static function weekday(string|DateTimeImmutable $value): string
    {
        $dt = is_string($value) ? self::parse($value) : $value;

        return $dt === null ? '' : $dt->format('D j M Y');
    }

    public static function time(string|DateTimeImmutable $value, bool $zone = true): string
    {
        $dt = is_string($value) ? self::parse($value) : $value;

        if ($dt === null) {
            return '';
        }

        return $dt->format('g:i A') . ($zone ? ' ' . self::ZONE_LABEL : '');
    }

    // short label for a slot like 2:30 PM
    public static function slotLabel(string $hhmm): string
    {
        return self::time('2000-01-01 ' . $hhmm, false);
    }

    // range text like 2:30 PM to 4:00 PM PHT
    public static function range(string $startsAt, string $endsAt): string
    {
        return self::time($startsAt, false) . ' to ' . self::time($endsAt);
    }

    public static function minutesBetween(string $startsAt, string $endsAt): int
    {
        $a = self::parse($startsAt);
        $b = self::parse($endsAt);

        return ($a === null || $b === null) ? 0 : (int) (($b->getTimestamp() - $a->getTimestamp()) / 60);
    }
}
