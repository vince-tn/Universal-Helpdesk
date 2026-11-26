<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\N8n;

final class CloudProviderTest extends CIUnitTestCase
{
    public function testWithNoKeysThereIsNoCloudProvider(): void
    {
        $this->assertSame(N8n::PROVIDER_NONE, N8n::providerFor('', '', ''));
        $this->assertSame(N8n::PROVIDER_NONE, N8n::providerFor('', '   ', '  '));
    }

    public function testASingleKeyPicksItsOwnProvider(): void
    {
        $this->assertSame(N8n::PROVIDER_OPENROUTER, N8n::providerFor('', 'sk-or-xxx', ''));
        $this->assertSame(N8n::PROVIDER_GEMINI, N8n::providerFor('', '', 'AIza-xxx'));
    }

    public function testOpenRouterWinsWhenBothKeysAreSet(): void
    {
        $this->assertSame(N8n::PROVIDER_OPENROUTER, N8n::providerFor('', 'sk-or-xxx', 'AIza-xxx'));
    }

    public function testNamingAProviderOverridesThatOrder(): void
    {
        $this->assertSame(N8n::PROVIDER_GEMINI, N8n::providerFor('gemini', 'sk-or-xxx', 'AIza-xxx'));
        $this->assertSame(N8n::PROVIDER_OPENROUTER, N8n::providerFor('openrouter', 'sk-or-xxx', 'AIza-xxx'));
    }

    public function testNamingAProviderWithNoKeyFallsBackToLocalOnly(): void
    {
        $this->assertSame(N8n::PROVIDER_NONE, N8n::providerFor('openrouter', '', 'AIza-xxx'));
        $this->assertSame(N8n::PROVIDER_NONE, N8n::providerFor('gemini', 'sk-or-xxx', ''));
    }

    public function testTheCloudCanBeTurnedOffByName(): void
    {
        $this->assertSame(N8n::PROVIDER_NONE, N8n::providerFor('none', 'sk-or-xxx', 'AIza-xxx'));
    }

    public function testTheNameIsReadLooselyAsPeopleTypeIt(): void
    {
        $this->assertSame(N8n::PROVIDER_OPENROUTER, N8n::providerFor('  OpenRouter  ', 'sk-or-xxx', ''));
    }
}
