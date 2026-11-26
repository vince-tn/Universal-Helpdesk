<?php

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Superglobals;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

final class ClientIpTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Services::resetSingle('superglobals');

        parent::tearDown();
    }

    private function ipFor(array $server): string
    {
        Services::injectMock('superglobals', new Superglobals($server, [], [], [], [], []));

        $config  = new App();
        $request = new IncomingRequest($config, new SiteURI($config), null, new UserAgent());

        return $request->getIPAddress();
    }

    public function testVisitorsThroughTheTunnelKeepTheirOwnAddress(): void
    {
        $this->assertSame('203.0.113.9', $this->ipFor([
            'REMOTE_ADDR'          => '172.18.0.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
    }

    public function testVisitorsThroughTheTailscaleSidecarKeepTheirOwnAddress(): void
    {
        $this->assertSame('203.0.113.9', $this->ipFor([
            'REMOTE_ADDR'          => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
    }

    public function testOnlyTheFirstAddressInTheChainIsUsed(): void
    {
        $this->assertSame('203.0.113.9', $this->ipFor([
            'REMOTE_ADDR'          => '172.18.0.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.7',
        ]));
    }

    public function testOfficeVisitorsCannotClaimAnotherAddress(): void
    {
        $this->assertSame('192.168.52.40', $this->ipFor([
            'REMOTE_ADDR'          => '192.168.52.40',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]));
    }

    public function testAGarbledHeaderFallsBackToTheConnection(): void
    {
        $this->assertSame('172.18.0.8', $this->ipFor([
            'REMOTE_ADDR'          => '172.18.0.8',
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
        ]));
    }
}
