<?php

use App\Libraries\MailParsing;
use CodeIgniter\Test\CIUnitTestCase;

final class MailParsingTest extends CIUnitTestCase
{
    public function testTicketIdComesFromTheBracketTag(): void
    {
        $this->assertSame(123, MailParsing::ticketId('RE: [#123] New reply: printer'));
        $this->assertSame(7, MailParsing::ticketId('[#7] We got your request'));
        $this->assertNull(MailParsing::ticketId('Ticket #123 is broken'));
        $this->assertNull(MailParsing::ticketId('[#0] zero'));
        $this->assertNull(MailParsing::ticketId('[#abc] letters'));
    }

    public function testCleanSubjectDropsPrefixesAndTag(): void
    {
        $this->assertSame('New reply: printer', MailParsing::cleanSubject('RE: Fwd: [#123] New reply: printer'));
        $this->assertSame('VPN down', MailParsing::cleanSubject('  re:  re: VPN   down '));
    }

    public function testMailboxMappingWithAndWithoutRole(): void
    {
        $routes = MailParsing::mailboxes('ITSupport@KinectInc.com=IT/Helpdesk, hr@kinectinc.com=HR', ['IT', 'HR']);

        $this->assertSame([
            ['mailbox' => 'itsupport@kinectinc.com', 'department' => 'IT', 'role' => 'Helpdesk'],
            ['mailbox' => 'hr@kinectinc.com', 'department' => 'HR', 'role' => ''],
        ], $routes);
    }

    public function testMailboxMappingKeepsDepartmentsWithASlash(): void
    {
        $routes = MailParsing::mailboxes('wfm@x.com=Reports/WFM,wfm2@x.com=reports/wfm/Scheduling', ['IT', 'Reports/WFM']);

        $this->assertSame('Reports/WFM', $routes[0]['department']);
        $this->assertSame('', $routes[0]['role']);
        $this->assertSame('Reports/WFM', $routes[1]['department']);
        $this->assertSame('Scheduling', $routes[1]['role']);
    }

    public function testMailboxMappingSkipsBadEntries(): void
    {
        $this->assertSame([], MailParsing::mailboxes('not-an-email=IT, it@x.com=, it@x.com, ,'));
    }

    public function testGmailHistoryIsCut(): void
    {
        $text = "Still broken after restart.\n\nOn Mon, 23 Nov 2026 at 10:00, Help Desk <itsupport@kinectinc.com> wrote:\n> Try a restart.\n> Thanks";

        $this->assertSame('Still broken after restart.', MailParsing::stripQuoted($text));
    }

    public function testWrappedGmailHeaderIsCut(): void
    {
        $text = "Thanks, fixed.\nOn Mon, 23 Nov 2026 at 10:00, Help Desk <\nitsupport@kinectinc.com> wrote:\nold text";

        $this->assertSame('Thanks, fixed.', MailParsing::stripQuoted($text));
    }

    public function testOutlookHistoryIsCut(): void
    {
        $text = "Works now.\r\n\r\n________________________________\r\nFrom: Help Desk\r\nSent: Monday\r\nSubject: [#5] Status";
        $this->assertSame('Works now.', MailParsing::stripQuoted($text));

        $text = "Works now.\n\nFrom: Help Desk <itsupport@kinectinc.com>\nSent: Monday, 23 November 2026 10:00\nTo: Jane";
        $this->assertSame('Works now.', MailParsing::stripQuoted($text));

        $text = "Works now.\n-----Original Message-----\nFrom: x";
        $this->assertSame('Works now.', MailParsing::stripQuoted($text));
    }

    public function testInlineQuotesAreDroppedButReplyKept(): void
    {
        $text = "> what is your pc name?\nPC-0042\n> and the error?\nIt says access denied";

        $this->assertSame("PC-0042\nIt says access denied", MailParsing::stripQuoted($text));
    }

    public function testPlainLinesStartingWithFromAreKept(): void
    {
        $text = "From: the third floor printer, nothing prints.\nPlease help.";

        $this->assertSame($text, MailParsing::stripQuoted($text));
    }

    public function testAutomatedSendersAreDetected(): void
    {
        $this->assertTrue(MailParsing::isAutomated('no-reply@vendor.com', []));
        $this->assertTrue(MailParsing::isAutomated('noreply@vendor.com', []));
        $this->assertTrue(MailParsing::isAutomated('MAILER-DAEMON@kinectinc.com', []));
        $this->assertTrue(MailParsing::isAutomated('jane@kinectinc.com', [['name' => 'Auto-Submitted', 'value' => 'auto-replied']]));
        $this->assertFalse(MailParsing::isAutomated('jane@kinectinc.com', [['name' => 'Auto-Submitted', 'value' => 'no']]));
        $this->assertFalse(MailParsing::isAutomated('jane@kinectinc.com', [['name' => 'Subject', 'value' => 'hi']]));
    }

    public function testHtmlBodyIsSanitisedAndHistoryCut(): void
    {
        $html = '<html><head><title>x</title><style>p{}</style></head><body><p>Still <b>broken</b>.</p><script>alert(1)</script>'
            . '<div class="gmail_quote">On Mon, Help Desk wrote:<blockquote>old</blockquote></div></body></html>';

        $this->assertSame('Still broken.', MailParsing::bodyText($html, true));
    }

    public function testBodyIsCapped(): void
    {
        $this->assertSame(MailParsing::MAX_BODY, mb_strlen(MailParsing::bodyText(str_repeat('a', 6000), false)));
    }
}
