<?php

use App\Libraries\AutoAssign;
use App\Libraries\Duplicates;
use App\Libraries\Watchers;
use CodeIgniter\Test\CIUnitTestCase;

final class TicketWorkflowTest extends CIUnitTestCase
{
    public function testRoundRobinPicksTheAgentWithFewestOpenTickets(): void
    {
        $this->assertSame(2, AutoAssign::pick([
            ['id' => 1, 'open' => 4, 'last' => '2026-11-01 09:00:00'],
            ['id' => 2, 'open' => 1, 'last' => '2026-11-27 09:00:00'],
            ['id' => 3, 'open' => 3, 'last' => null],
        ]));
    }

    public function testRoundRobinTiesGoToTheLongestSinceLastAssignment(): void
    {
        $this->assertSame(5, AutoAssign::pick([
            ['id' => 4, 'open' => 2, 'last' => '2026-11-27 09:00:00'],
            ['id' => 5, 'open' => 2, 'last' => '2026-11-20 09:00:00'],
        ]));
    }

    public function testNeverAssignedAgentsWinATie(): void
    {
        $this->assertSame(7, AutoAssign::pick([
            ['id' => 6, 'open' => 0, 'last' => '2026-11-20 09:00:00'],
            ['id' => 7, 'open' => 0, 'last' => null],
        ]));
    }

    public function testRoundRobinWithNobodyReturnsNull(): void
    {
        $this->assertNull(AutoAssign::pick([]));
    }

    public function testFullTieFallsBackToLowestId(): void
    {
        $this->assertSame(3, AutoAssign::pick([
            ['id' => 9, 'open' => 1, 'last' => null],
            ['id' => 3, 'open' => 1, 'last' => null],
        ]));
    }

    public function testSameRequestScoresHigh(): void
    {
        $a = 'My laptop will not connect to the office wifi since this morning';
        $b = 'Laptop cannot connect to office wifi since this morning';

        $this->assertGreaterThanOrEqual(Duplicates::THRESHOLD, Duplicates::similarity($a, $b));
    }

    public function testDifferentRequestsScoreLow(): void
    {
        $a = 'My laptop will not connect to the office wifi';
        $b = 'Please process my leave request for December payroll';

        $this->assertLessThan(Duplicates::THRESHOLD, Duplicates::similarity($a, $b));
    }

    public function testEmptyTextScoresZero(): void
    {
        $this->assertSame(0.0, Duplicates::similarity('', 'printer jam on level two'));
        $this->assertSame(0.0, Duplicates::similarity('the and for', 'printer jam'));
    }

    public function testSimilarityIgnoresCaseAndStopWords(): void
    {
        $this->assertSame(1.0, Duplicates::similarity('PRINTER JAM level two', 'please help, printer jam on level two'));
    }

    public function testWatcherParsingCleansAndDedupes(): void
    {
        $parsed = Watchers::parse(" Ann@Corp.com, bob@corp.com; ann@corp.com\n<cara@corp.com>");

        $this->assertSame(['ann@corp.com', 'bob@corp.com', 'cara@corp.com'], $parsed['emails']);
        $this->assertSame([], $parsed['invalid']);
        $this->assertFalse($parsed['too_many']);
    }

    public function testWatcherParsingFlagsWhatIsNotAnEmail(): void
    {
        $parsed = Watchers::parse('ann@corp.com, bob, @corp.com');

        $this->assertSame(['ann@corp.com'], $parsed['emails']);
        $this->assertSame(['bob', '@corp.com'], $parsed['invalid']);
    }

    public function testWatcherParsingCapsTheList(): void
    {
        $list   = implode(',', array_map(static fn ($i) => "user{$i}@corp.com", range(1, 12)));
        $parsed = Watchers::parse($list);

        $this->assertCount(Watchers::MAX, $parsed['emails']);
        $this->assertTrue($parsed['too_many']);
    }

    public function testEmptyWatcherFieldIsFine(): void
    {
        $this->assertSame(['emails' => [], 'invalid' => [], 'too_many' => false], Watchers::parse('   '));
    }

    public function testRequesterSideCoversRequestedFor(): void
    {
        $fields = ['Requester Email' => 'ann@corp.com', 'Requested For' => 'bob@corp.com'];

        $this->assertTrue(Watchers::isRequesterSide(['email' => 'Ann@corp.com'], $fields));
        $this->assertTrue(Watchers::isRequesterSide(['email' => 'bob@corp.com'], $fields));
        $this->assertFalse(Watchers::isRequesterSide(['email' => 'cara@corp.com'], $fields));
        $this->assertFalse(Watchers::isRequesterSide(['email' => ''], ['Requester Email' => '', 'Requested For' => '']));
    }
}
