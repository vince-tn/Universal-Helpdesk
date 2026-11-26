<?php

use App\Libraries\Notifier;
use CodeIgniter\Test\CIUnitTestCase;

final class NotifierTest extends CIUnitTestCase
{
    public function testTheSubjectAlwaysCarriesTheTicketNumber(): void
    {
        $this->assertSame(
            'Logged ticket #7: Laptop Running Slowly',
            Notifier::subjectFor('raised', '7', 'Laptop Running Slowly')
        );
        $this->assertSame(
            'New reply on ticket #7: Laptop Running Slowly',
            Notifier::subjectFor('replied', '7', 'Laptop Running Slowly')
        );
        $this->assertSame(
            'Assigned to you ticket #12: VPN Access Request',
            Notifier::subjectFor('assigned', '12', 'VPN Access Request')
        );
        $this->assertSame(
            'Status changed on ticket #3: Printer Offline',
            Notifier::subjectFor('status', '3', 'Printer Offline')
        );
    }

    public function testAnUnclassifiedTicketStillGetsAReadableSubject(): void
    {
        $this->assertSame('Logged ticket #9: your request', Notifier::subjectFor('raised', '9', '   '));
    }

    public function testSubjectsStayWithinAHeaderLine(): void
    {
        $this->assertLessThanOrEqual(160, mb_strlen(Notifier::subjectFor('raised', '1', str_repeat('long title ', 40))));
    }

    public function testTheExcerptIsFlattenedAndTrimmed(): void
    {
        $this->assertSame('One two three', Notifier::excerpt("  One\n\ttwo   three  "));
    }

    public function testALongRequestIsCutWithAnEllipsis(): void
    {
        $out = Notifier::excerpt(str_repeat('a', 400));

        $this->assertSame(Notifier::EXCERPT_CHARS + 3, mb_strlen($out));
        $this->assertStringEndsWith('...', $out);
    }

    public function testAShortRequestIsLeftAlone(): void
    {
        $this->assertSame('My laptop is slow.', Notifier::excerpt('My laptop is slow.'));
    }
}
