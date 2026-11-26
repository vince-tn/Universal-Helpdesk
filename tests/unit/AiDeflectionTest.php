<?php

use App\Libraries\AiDeflection;
use CodeIgniter\Test\CIUnitTestCase;

final class AiDeflectionTest extends CIUnitTestCase
{
    private const BOT = ['user_id' => null, 'actor_name' => 'HelpDesk Assistant'];

    private function ai(string $kind): array
    {
        return ['author_role' => 'ai', 'kind' => $kind, 'user_id' => null, 'author_name' => 'HelpDesk Assistant', 'is_internal' => 0];
    }

    private function from(string $role, int $userId, string $name, int $internal = 0): array
    {
        return ['author_role' => $role, 'kind' => 'message', 'user_id' => $userId, 'author_name' => $name, 'is_internal' => $internal];
    }

    public function testAssistantResolveAfterSuggestionCounts(): void
    {
        $messages = [$this->from('requester', 3, 'Ana'), $this->ai('suggestion'), $this->from('requester', 3, 'Ana')];

        $this->assertTrue(AiDeflection::isDeflected($messages, self::BOT, 3, 'Ana'));
    }

    public function testRequesterResolveAfterGuideCounts(): void
    {
        $this->assertTrue(AiDeflection::isDeflected([$this->ai('guide')], ['user_id' => 3, 'actor_name' => 'Ana'], 3, 'Ana'));
    }

    public function testStaffReplyMeansNotDeflected(): void
    {
        $messages = [$this->ai('suggestion'), $this->from('agent', 8, 'Ben')];

        $this->assertFalse(AiDeflection::isDeflected($messages, self::BOT, 3, 'Ana'));
    }

    public function testInternalNotesAndStaffRequestersDoNotBlock(): void
    {
        $messages = [$this->ai('suggestion'), $this->from('agent', 8, 'Ben', 1), $this->from('agent', 3, 'Ana')];

        $this->assertTrue(AiDeflection::isDeflected($messages, self::BOT, 3, 'Ana'));
    }

    public function testNeedsSelfHelpAndTheRightResolver(): void
    {
        $this->assertFalse(AiDeflection::isDeflected([$this->ai('acknowledge')], self::BOT, 3, 'Ana'));
        $this->assertFalse(AiDeflection::isDeflected([$this->ai('suggestion')], ['user_id' => 8, 'actor_name' => 'Ben'], 3, 'Ana'));
        $this->assertFalse(AiDeflection::isDeflected([$this->ai('suggestion')], null, 3, 'Ana'));
    }

    public function testRateableAndPercent(): void
    {
        $this->assertTrue(AiDeflection::rateable($this->ai('suggestion')));
        $this->assertFalse(AiDeflection::rateable($this->ai('handoff')));
        $this->assertFalse(AiDeflection::rateable($this->from('agent', 8, 'Ben')));
        $this->assertSame(25.0, AiDeflection::percent(1, 4));
        $this->assertNull(AiDeflection::percent(0, 0));
    }
}
