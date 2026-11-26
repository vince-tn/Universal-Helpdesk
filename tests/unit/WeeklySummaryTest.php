<?php

use App\Libraries\WeeklySummary;
use CodeIgniter\Test\CIUnitTestCase;

final class WeeklySummaryTest extends CIUnitTestCase
{
    private function manila(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('Asia/Manila'));
    }

    public function testDueOnMondayMorningOnce(): void
    {
        $this->assertTrue(WeeklySummary::isDue($this->manila('2026-11-30 08:00'), ''));
        $this->assertTrue(WeeklySummary::isDue($this->manila('2026-11-30 08:10'), '2026-11-23 08:05:00'));
        $this->assertFalse(WeeklySummary::isDue($this->manila('2026-11-30 08:05'), '2026-11-30 08:00:00'));
    }

    public function testNotDueOutsideTheWindow(): void
    {
        $this->assertFalse(WeeklySummary::isDue($this->manila('2026-11-30 07:59'), ''));
        $this->assertFalse(WeeklySummary::isDue($this->manila('2026-11-30 08:11'), ''));
        $this->assertFalse(WeeklySummary::isDue($this->manila('2026-12-01 08:05'), ''));
    }

    public function testUsesManilaTimeEvenFromUtc(): void
    {
        $utc = new DateTimeImmutable('2026-11-30 00:05', new DateTimeZone('UTC'));

        $this->assertTrue(WeeklySummary::isDue($utc, ''));
    }

    public function testBuckets(): void
    {
        $this->assertSame('0 to 2 days', WeeklySummary::bucket(2));
        $this->assertSame('3 to 5 days', WeeklySummary::bucket(3));
        $this->assertSame('6+ days', WeeklySummary::bucket(6));
    }

    public function testTextHasEveryFigure(): void
    {
        $stats = [
            'new' => 12, 'resolved' => 8, 'open' => 9,
            'ageing' => ['0 to 2 days' => 4, '3 to 5 days' => 3, '6+ days' => 2],
            'slaMet' => 6, 'slaBreached' => 2,
            'categories' => ['Access' => 2, 'Hardware' => 5, 'Email' => 3, 'Other' => 1],
            'csat' => [5, 4], 'deflected' => 2,
        ];

        $text = WeeklySummary::text($stats, 'IT', '23 Nov 2026', '30 Nov 2026', 'https://desk.example/tickets');

        $this->assertStringContainsString('Weekly helpdesk summary for IT', $text);
        $this->assertStringContainsString('New tickets: 12', $text);
        $this->assertStringContainsString('Resolved: 8', $text);
        $this->assertStringContainsString('Open backlog: 9', $text);
        $this->assertStringContainsString('6+ days: 2', $text);
        $this->assertStringContainsString('SLA met: 6', $text);
        $this->assertStringContainsString('SLA breached: 2', $text);
        $this->assertStringContainsString("1. Hardware (5)\n  2. Email (3)\n  3. Access (2)", $text);
        $this->assertStringNotContainsString('Other', $text);
        $this->assertStringContainsString('CSAT average: 4.5 / 5 from 2 ratings', $text);
        $this->assertStringContainsString('AI deflection: 25% (2 of 8', $text);
    }

    public function testTextForAQuietWeek(): void
    {
        $text = WeeklySummary::text([], 'all departments', 'a', 'b', 'x');

        $this->assertStringContainsString('None this week', $text);
        $this->assertStringContainsString('no ratings this week', $text);
        $this->assertStringContainsString('nothing resolved this week', $text);
    }
}
