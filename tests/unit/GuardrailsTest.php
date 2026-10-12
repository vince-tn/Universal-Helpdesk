<?php

use App\Libraries\Guardrails;
use App\Libraries\KnowledgeBase;
use CodeIgniter\Test\CIUnitTestCase;

final class GuardrailsTest extends CIUnitTestCase
{
    public function testMatchesWholeWordsOnly(): void
    {
        $this->assertSame('pay', Guardrails::firstMatch(['pay'], 'Where is my pay?'));
        $this->assertNull(Guardrails::firstMatch(['pay'], 'My payroll is wrong'));
    }

    public function testTrailingStarMatchesAnyEnding(): void
    {
        $this->assertSame('pay*', Guardrails::firstMatch(['pay*'], 'My payroll is wrong'));
        $this->assertNull(Guardrails::firstMatch(['pay*'], 'Please repay me'));
    }

    public function testPhrasesIgnoreCaseAndSpacing(): void
    {
        $this->assertSame('sick leave', Guardrails::firstMatch(['sick leave'], "I need SICK\n  leave tomorrow"));
    }

    public function testRegexCharactersAreTakenLiterally(): void
    {
        $this->assertSame('c++', Guardrails::firstMatch(['c++'], 'I write c++ every day'));
        $this->assertNull(Guardrails::firstMatch(['a.b'], 'axb'));
    }

    public function testAccentedLettersCountAsPartOfAWord(): void
    {
        $this->assertNull(Guardrails::firstMatch(['caf'], 'Meet at the café'));
        $this->assertSame('café', Guardrails::firstMatch(['café'], 'Meet at the CAFÉ'));
    }

    public function testEmptyTermsAndTextNeverMatch(): void
    {
        $this->assertNull(Guardrails::firstMatch(['', '*', '   '], 'anything at all'));
        $this->assertNull(Guardrails::firstMatch(['refund'], ''));
    }

    public function testReturnsTheFirstTermThatMatches(): void
    {
        $this->assertSame('salary', Guardrails::firstMatch(['bonus', 'salary', 'pay'], 'My salary and pay are late'));
    }

    public function testContextNumbersArticlesAndTruncatesLongOnes(): void
    {
        $text = (new KnowledgeBase())->context([
            ['title' => 'VPN drops', 'body' => 'Reinstall the profile.'],
            ['title' => 'Long one', 'body' => str_repeat('x', 1000)],
        ]);

        $this->assertStringContainsString("ARTICLE 1: VPN drops\nReinstall the profile.", $text);
        $this->assertStringContainsString('ARTICLE 2: Long one', $text);
        $this->assertStringEndsWith('[...]', $text);
        $this->assertSame('', (new KnowledgeBase())->context([]));
    }
}
