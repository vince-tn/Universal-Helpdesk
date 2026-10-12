<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DateHelperTest extends CIUnitTestCase
{
    public function testAcceptsRealDates(): void
    {
        $this->assertTrue(is_ymd_date('2026-10-12'));
        $this->assertTrue(is_ymd_date('2028-02-29'));
    }

    public function testRejectsDatesThatDoNotExist(): void
    {
        $this->assertFalse(is_ymd_date('2026-02-30'));
        $this->assertFalse(is_ymd_date('2026-13-01'));
        $this->assertFalse(is_ymd_date('2027-02-29'));
    }

    public function testRejectsAnythingNotWrittenAsYearMonthDay(): void
    {
        $this->assertFalse(is_ymd_date(''));
        $this->assertFalse(is_ymd_date('12/10/2026'));
        $this->assertFalse(is_ymd_date('2026-10-12 08:00:00'));
        $this->assertFalse(is_ymd_date('next friday'));
        $this->assertFalse(is_ymd_date("2026-10-12'; DROP TABLE tickets; --"));
    }
}
