<?php

use App\Libraries\PasswordReset;
use CodeIgniter\Test\CIUnitTestCase;

final class PasswordResetTest extends CIUnitTestCase
{
    private function row(string $code, int $expiresAt, int $attempts = 0, ?string $consumed = null): array
    {
        return [
            'id'          => 1,
            'code_hash'   => password_hash($code, PASSWORD_DEFAULT),
            'expires_at'  => date('Y-m-d H:i:s', $expiresAt),
            'attempts'    => $attempts,
            'consumed_at' => $consumed,
        ];
    }

    public function testTheRightCodeWorksOnceAndIsBurned(): void
    {
        $now    = time();
        $result = PasswordReset::assess($this->row('123456', $now + 600), '123 456', $now);

        $this->assertSame(PasswordReset::OK, $result['status']);
        $this->assertTrue($result['burn']);
    }

    public function testNoRowOrAUsedCodeMeansNoCode(): void
    {
        $now = time();

        $this->assertSame(PasswordReset::NO_CODE, PasswordReset::assess(null, '123456', $now)['status']);
        $this->assertSame(PasswordReset::NO_CODE, PasswordReset::assess($this->row('123456', $now + 600, 0, date('Y-m-d H:i:s')), '123456', $now)['status']);
    }

    public function testCodesExpireAfterFifteenMinutes(): void
    {
        $issued = time() - 1000;
        $row    = $this->row('123456', $issued + PasswordReset::TTL_SECONDS);

        $this->assertSame(900, PasswordReset::TTL_SECONDS);
        $this->assertSame(PasswordReset::OK, PasswordReset::assess($row, '123456', $issued + 899)['status']);
        $this->assertSame(PasswordReset::EXPIRED, PasswordReset::assess($row, '123456', $issued + 900)['status']);
    }

    public function testWrongCodesCountDownToTooMany(): void
    {
        $now = time();

        $first = PasswordReset::assess($this->row('123456', $now + 600, 0), '000000', $now);
        $this->assertSame(PasswordReset::WRONG, $first['status']);
        $this->assertSame(1, $first['attempts']);
        $this->assertFalse($first['burn']);

        $fifth = PasswordReset::assess($this->row('123456', $now + 600, 4), '000000', $now);
        $this->assertSame(PasswordReset::TOO_MANY, $fifth['status']);
        $this->assertTrue($fifth['burn']);
    }

    public function testTheRightCodeFailsOnceAttemptsAreUsedUp(): void
    {
        $now = time();

        $this->assertSame(PasswordReset::TOO_MANY, PasswordReset::assess($this->row('123456', $now + 600, 5), '123456', $now)['status']);
        $this->assertSame(PasswordReset::OK, PasswordReset::assess($this->row('123456', $now + 600, 4), '123456', $now)['status']);
    }

    public function testResendCooldown(): void
    {
        $now = time();

        $this->assertSame(0, PasswordReset::cooldownLeft(null, $now));
        $this->assertSame(50, PasswordReset::cooldownLeft(date('Y-m-d H:i:s', $now - 10), $now));
        $this->assertSame(0, PasswordReset::cooldownLeft(date('Y-m-d H:i:s', $now - 61), $now));
    }

    public function testCodesAreSixDigits(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', PasswordReset::mintCode());
        }
    }

    public function testDisabledOrExpiredAccountsCannotReset(): void
    {
        $this->assertTrue(PasswordReset::eligible(['is_active' => 1, 'expires_at' => null]));
        $this->assertFalse(PasswordReset::eligible(null));
        $this->assertFalse(PasswordReset::eligible(['is_active' => 0, 'expires_at' => null]));
        $this->assertFalse(PasswordReset::eligible(['is_active' => 1, 'expires_at' => date('Y-m-d H:i:s', time() - 60)]));
    }
}
