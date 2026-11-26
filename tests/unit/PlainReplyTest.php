<?php

use App\Libraries\TicketAssistant;
use CodeIgniter\Test\CIUnitTestCase;

final class PlainReplyTest extends CIUnitTestCase
{
    public function testAnEchoedArticleHeadingIsRemoved(): void
    {
        $this->assertSame(
            "Reset your password\nThen restart.",
            TicketAssistant::plainReply("ARTICLE 1: Reset your password\nThen restart.")
        );
    }

    public function testAnEchoedPriorCaseHeadingIsRemoved(): void
    {
        $this->assertSame('', TicketAssistant::plainReply('CASE 1 (ticket #5, already resolved)'));
    }

    public function testAnArticleReferenceBecomesPlainEnglish(): void
    {
        $this->assertSame(
            'Follow the steps in the guide.',
            TicketAssistant::plainReply('Follow the steps in ARTICLE 2.')
        );
        $this->assertSame(
            'As described in the guide, clear the cache.',
            TicketAssistant::plainReply('As described in Article 3, clear the cache.')
        );
    }

    public function testAReferenceAtTheStartOfASentenceIsCapitalised(): void
    {
        $this->assertSame(
            'The guide says to restart the client.',
            TicketAssistant::plainReply('ARTICLE 1 says to restart the client.')
        );
    }

    public function testPriorCasesAndTicketNumbersAreNotNamed(): void
    {
        $this->assertSame(
            'This has come up before, see an earlier request.',
            TicketAssistant::plainReply('This has come up before, see CASE 1.')
        );
        $this->assertSame(
            'Please check an earlier ticket for details.',
            TicketAssistant::plainReply('Please check ticket #42 for details.')
        );
    }

    public function testALegalCitationIsNotMistakenForOurOwnLabel(): void
    {
        $this->assertSame(
            'Article 15 of the Data Privacy Act applies here.',
            TicketAssistant::plainReply('Article 15 of the Data Privacy Act applies here.')
        );
    }

    public function testOrdinaryAdviceIsLeftAlone(): void
    {
        $this->assertSame(
            'Just restart the router and try again.',
            TicketAssistant::plainReply('Just restart the router and try again.')
        );
    }

    public function testEmptyInputStaysEmpty(): void
    {
        $this->assertSame('', TicketAssistant::plainReply(''));
        $this->assertSame('', TicketAssistant::plainReply('   '));
    }
}
