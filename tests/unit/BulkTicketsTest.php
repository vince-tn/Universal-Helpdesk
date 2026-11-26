<?php

use App\Libraries\BulkTickets;
use CodeIgniter\Test\CIUnitTestCase;

final class BulkTicketsTest extends CIUnitTestCase
{
    private function ticket(array $over = []): array
    {
        return $over + ['can_manage' => true, 'status' => 'In Progress', 'assigned_to' => 5, 'priority' => 'Medium', 'assignable' => [5, 7]];
    }

    public function testOnlyLeadsCanUseBulk(): void
    {
        $this->assertTrue(BulkTickets::canUse(['role' => 'superadmin']));
        $this->assertTrue(BulkTickets::canUse(['role' => 'supervisor']));
        $this->assertTrue(BulkTickets::canUse(['role' => 'team_leader']));
        $this->assertFalse(BulkTickets::canUse(['role' => 'agent']));
        $this->assertFalse(BulkTickets::canUse(null));
    }

    public function testResolvedNeedsANote(): void
    {
        $this->assertIsString(BulkTickets::requestError('status', 'Resolved', 'short'));
        $this->assertNull(BulkTickets::requestError('status', 'Resolved', 'Reset the password for them'));
        $this->assertNull(BulkTickets::requestError('status', 'On Hold', ''));
        $this->assertIsString(BulkTickets::requestError('status', 'Bogus', ''));
        $this->assertIsString(BulkTickets::requestError('nope', '', ''));
        $this->assertIsString(BulkTickets::requestError('assign', '0', ''));
        $this->assertIsString(BulkTickets::requestError('priority', 'Huge', ''));
        $this->assertNull(BulkTickets::requestError('close', '', ''));
    }

    public function testResolvedSkipsTicketsWithNoAssignee(): void
    {
        $this->assertSame('unassigned', BulkTickets::skipReason('status', 'Resolved', $this->ticket(['assigned_to' => 0]), false));
        $this->assertNull(BulkTickets::skipReason('status', 'Resolved', $this->ticket(), false));
        $this->assertNull(BulkTickets::skipReason('status', 'On Hold', $this->ticket(['assigned_to' => 0]), false));
    }

    public function testAccessUnchangedAndApproval(): void
    {
        $this->assertSame('access', BulkTickets::skipReason('priority', 'High', $this->ticket(['can_manage' => false]), true));
        $this->assertSame('unchanged', BulkTickets::skipReason('priority', 'Medium', $this->ticket(), false));
        $this->assertSame('unchanged', BulkTickets::skipReason('close', '', $this->ticket(['status' => 'Closed']), false));
        $this->assertSame('approval', BulkTickets::skipReason('close', '', $this->ticket(['status' => 'Pending Approval']), false));
        $this->assertNull(BulkTickets::skipReason('close', '', $this->ticket(['status' => 'Pending Approval']), true));
    }

    public function testAssignNeedsSomeoneOnTheTeam(): void
    {
        $this->assertNull(BulkTickets::skipReason('assign', '7', $this->ticket(), false));
        $this->assertSame('unchanged', BulkTickets::skipReason('assign', '5', $this->ticket(), false));
        $this->assertSame('not_assignable', BulkTickets::skipReason('assign', '9', $this->ticket(), false));
    }

    public function testSummaryText(): void
    {
        $this->assertSame('Updated 7 tickets.', BulkTickets::summary(7, []));
        $this->assertSame('Updated 1 ticket.', BulkTickets::summary(1, ['unassigned' => 0]));
        $this->assertSame('Updated 7 tickets, skipped 2 (2 with no assignee).', BulkTickets::summary(7, ['unassigned' => 2]));
        $this->assertSame('Updated 0 tickets, skipped 3 (1 already set, 2 not yours to manage).', BulkTickets::summary(0, ['unchanged' => 1, 'access' => 2]));
    }
}
