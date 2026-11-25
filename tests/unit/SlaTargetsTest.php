<?php

use App\Libraries\SlaTargets;
use CodeIgniter\Test\CIUnitTestCase;

final class SlaTargetsTest extends CIUnitTestCase
{
    public function testHoursBecomeMinutes(): void
    {
        $this->assertSame([30, 240], SlaTargets::validate('0.5', '4'));
        $this->assertSame([90, 1440], SlaTargets::validate(' 1.5 ', '24'));
        $this->assertSame([15, 60], SlaTargets::validate('.25', '1'));
    }

    public function testResponseMustBeShorterThanResolution(): void
    {
        $this->assertIsString(SlaTargets::validate('4', '4'));
        $this->assertIsString(SlaTargets::validate('8', '4'));
    }

    public function testZeroNegativeAndJunkAreRejected(): void
    {
        $this->assertIsString(SlaTargets::validate('0', '4'));
        $this->assertIsString(SlaTargets::validate('-1', '4'));
        $this->assertIsString(SlaTargets::validate('abc', '4'));
        $this->assertIsString(SlaTargets::validate('', '4'));
        $this->assertIsString(SlaTargets::validate('1,5', '4'));
        $this->assertIsString(SlaTargets::validate('0.001', '4'));
    }

    public function testNinetyDaysIsTheCeiling(): void
    {
        $this->assertSame([60, 129600], SlaTargets::validate('1', '2160'));
        $this->assertIsString(SlaTargets::validate('1', '2160.5'));
    }

    public function testHoursLabelForInputs(): void
    {
        $this->assertSame('0.5', SlaTargets::hours(30));
        $this->assertSame('24', SlaTargets::hours(1440));
        $this->assertSame('1.25', SlaTargets::hours(75));
    }

    public function testSnapshotComparisonIgnoresOrderAndExtraColumns(): void
    {
        $a = [
            ['id' => 1, 'category' => 'Common Request', 'priority' => 'High', 'response_minutes' => '60', 'resolution_minutes' => '480'],
            ['id' => 2, 'category' => 'Common Request', 'priority' => 'Low', 'response_minutes' => '480', 'resolution_minutes' => '4320'],
        ];
        $b = array_reverse(SlaTargets::snapshot($a));

        $this->assertTrue(SlaTargets::same($a, $b));

        $b[0]['resolution_minutes'] = 5000;
        $this->assertFalse(SlaTargets::same($a, $b));
    }
}
