<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

final class PublicHostTest extends CIUnitTestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->saved = [
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'env'  => $_ENV['PUBLIC_HOST_SUFFIXES'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        $this->restore('HTTP_HOST', $this->saved['host'], $_SERVER);
        $this->restore('PUBLIC_HOST_SUFFIXES', $this->saved['env'], $_ENV);

        parent::tearDown();
    }

    private function restore(string $key, ?string $value, array &$store): void
    {
        if ($value === null) {
            unset($store[$key]);
        } else {
            $store[$key] = $value;
        }
    }

    private function baseUrlFor(string $host, string $suffixes): string
    {
        $_SERVER['HTTP_HOST']         = $host;
        $_ENV['PUBLIC_HOST_SUFFIXES'] = $suffixes;

        return (new App())->baseURL;
    }

    public function testATunnelAddressGetsItsOwnHttpsLinks(): void
    {
        $this->assertSame(
            'https://calm-river-1234.trycloudflare.com/',
            $this->baseUrlFor('calm-river-1234.trycloudflare.com', '.trycloudflare.com')
        );
        $this->assertSame(
            'https://calm-river-1234.trycloudflare.com/',
            $this->baseUrlFor('Calm-River-1234.TryCloudflare.com:443', 'trycloudflare.com')
        );
    }

    public function testOtherAddressesKeepTheConfiguredBase(): void
    {
        $default = $this->baseUrlFor('10.20.30.40', '');

        $this->assertSame($default, $this->baseUrlFor('10.20.30.40', '.trycloudflare.com'));
        $this->assertSame($default, $this->baseUrlFor('trycloudflare.com', '.trycloudflare.com'));
        $this->assertSame($default, $this->baseUrlFor('abc.trycloudflare.com.evil.com', '.trycloudflare.com'));
        $this->assertSame($default, $this->baseUrlFor('eviltrycloudflare.com', '.trycloudflare.com'));
        $this->assertSame($default, $this->baseUrlFor('calm-river-1234.trycloudflare.com', ''));
    }

    public function testListedLanHostsAnswerOnTheirOwnAddress(): void
    {
        $saved = $_ENV['LAN_HOSTS'] ?? null;
        $_ENV['LAN_HOSTS'] = '192.168.52.87:8094';

        $this->assertSame('http://192.168.52.87:8094/', $this->baseUrlFor('192.168.52.87:8094', ''));

        $this->restore('LAN_HOSTS', $saved, $_ENV);
    }
}
