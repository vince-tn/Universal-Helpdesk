<?php

use App\Libraries\Totp;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

// rfc 6238 appendix b sha1 vectors plus window and replay rules
final class TotpTest extends CIUnitTestCase
{
    private const SEED = '12345678901234567890';

    public static function rfcVectors(): array
    {
        return [
            'T=59'          => [59, '94287082'],
            'T=1111111109'  => [1111111109, '07081804'],
            'T=1111111111'  => [1111111111, '14050471'],
            'T=1234567890'  => [1234567890, '89005924'],
            'T=2000000000'  => [2000000000, '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function testMatchesRfcEightDigitVectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code(self::SEED, Totp::step($time), 8));
    }

    #[DataProvider('rfcVectors')]
    public function testSixDigitCodesAreTheLastSixDigits(int $time, string $expected): void
    {
        $this->assertSame(substr($expected, -6), Totp::code(self::SEED, Totp::step($time)));
    }

    public function testBase32RoundTripsTheRfcSeed(): void
    {
        $encoded = Totp::base32Encode(self::SEED);

        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $encoded);
        $this->assertSame(self::SEED, Totp::base32Decode($encoded));
        $this->assertSame(self::SEED, Totp::base32Decode('gezd gnbv gy3t qojq gezd gnbv gy3t qojq'));
        $this->assertNull(Totp::base32Decode('not base32!'));
    }

    public function testGeneratedSecretsDecodeToTwentyBytes(): void
    {
        $this->assertSame(20, strlen((string) Totp::base32Decode(Totp::generateSecret())));
    }

    public function testAcceptsOneStepEitherSide(): void
    {
        $now  = 1111111111;
        $step = Totp::step($now);

        $this->assertSame($step - 1, Totp::verify(self::SEED, Totp::code(self::SEED, $step - 1), null, $now));
        $this->assertSame($step, Totp::verify(self::SEED, Totp::code(self::SEED, $step), null, $now));
        $this->assertSame($step + 1, Totp::verify(self::SEED, Totp::code(self::SEED, $step + 1), null, $now));
        $this->assertNull(Totp::verify(self::SEED, Totp::code(self::SEED, $step + 2), null, $now));
        $this->assertNull(Totp::verify(self::SEED, Totp::code(self::SEED, $step - 2), null, $now));
    }

    public function testBlocksReplayOfTheLastUsedStep(): void
    {
        $now  = 1234567890;
        $step = Totp::step($now);
        $code = Totp::code(self::SEED, $step);

        $this->assertSame($step, Totp::verify(self::SEED, $code, $step - 1, $now));
        $this->assertNull(Totp::verify(self::SEED, $code, $step, $now));
        $this->assertNull(Totp::verify(self::SEED, Totp::code(self::SEED, $step - 1), $step, $now));
    }

    public function testRejectsMalformedCodes(): void
    {
        $this->assertNull(Totp::verify(self::SEED, '', null, 59));
        $this->assertNull(Totp::verify(self::SEED, '12345', null, 59));
        $this->assertNull(Totp::verify(self::SEED, 'abcdef', null, 59));
    }

    public function testProvisioningUriCarriesTheSettings(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'ana@example.com', 'Service Desk');

        $this->assertStringStartsWith('otpauth://totp/Service%20Desk:ana%40example.com?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('algorithm=SHA1&digits=6&period=30', $uri);
    }
}
