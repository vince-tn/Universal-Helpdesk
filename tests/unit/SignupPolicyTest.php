<?php

use App\Libraries\SignupPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SignupPolicyTest extends CIUnitTestCase
{
    public function testAPartnerDomainLetsItsPeopleAndSubdomainsIn(): void
    {
        $this->assertTrue(SignupPolicy::matches('jane@agency.com.au', ['agency.com.au'], []));
        $this->assertTrue(SignupPolicy::matches('Jane@Mail.Agency.com.au', ['agency.com.au'], []));
    }

    public function testLookAlikeDomainsStayOut(): void
    {
        $this->assertFalse(SignupPolicy::matches('jane@notagency.com.au', ['agency.com.au'], []));
        $this->assertFalse(SignupPolicy::matches('jane@agency.com.au.evil.com', ['agency.com.au'], []));
    }

    public function testASingleAddressLetsOnlyThatPersonIn(): void
    {
        $this->assertTrue(SignupPolicy::matches('jane@gmail.com', [], ['jane@gmail.com']));
        $this->assertFalse(SignupPolicy::matches('john@gmail.com', [], ['jane@gmail.com']));
    }

    public function testMalformedAddressesNeverMatch(): void
    {
        $this->assertFalse(SignupPolicy::matches('agency.com.au', ['agency.com.au'], []));
        $this->assertFalse(SignupPolicy::matches('@agency.com.au', ['agency.com.au'], []));
    }

    public function testParseTellsDomainsFromAddresses(): void
    {
        $this->assertSame(['domain', 'agency.com.au'], SignupPolicy::parse('  @Agency.com.au '));
        $this->assertSame(['email', 'jane@agency.com'], SignupPolicy::parse('Jane@Agency.com'));
    }

    public function testParseRejectsWhatIsNeither(): void
    {
        $this->assertNull(SignupPolicy::parse(''));
        $this->assertNull(SignupPolicy::parse('agency'));
        $this->assertNull(SignupPolicy::parse('not an email@'));
        $this->assertNull(SignupPolicy::parse('agency..com'));
        $this->assertNull(SignupPolicy::parse('-agency.com'));
    }

    public function testFreeMailServicesAreRecognised(): void
    {
        $this->assertTrue(SignupPolicy::isPublicProvider('Gmail.com'));
        $this->assertFalse(SignupPolicy::isPublicProvider('agency.com.au'));
    }
}
