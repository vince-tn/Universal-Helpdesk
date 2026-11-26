<?php

use App\Models\DepartmentModel;
use CodeIgniter\Test\CIUnitTestCase;

final class CloudEscalationTest extends CIUnitTestCase
{
    public function testADepartmentMayUseTheCloudUnlessItIsTurnedOff(): void
    {
        $this->assertTrue(DepartmentModel::rowAllowsCloud(['name' => 'IT', 'allow_cloud_ai' => 1]));
        $this->assertFalse(DepartmentModel::rowAllowsCloud(['name' => 'HR', 'allow_cloud_ai' => 0]));
    }

    public function testTheColumnIsReadAsAFlagNotAString(): void
    {
        $this->assertFalse(DepartmentModel::rowAllowsCloud(['allow_cloud_ai' => '0']));
        $this->assertTrue(DepartmentModel::rowAllowsCloud(['allow_cloud_ai' => '1']));
    }

    public function testADepartmentWithNoSettingKeepsTodaysBehaviour(): void
    {
        $this->assertTrue(DepartmentModel::rowAllowsCloud(['name' => 'IT']));
    }

    public function testAnUnknownDepartmentIsNotSilentlyDowngraded(): void
    {
        $this->assertTrue(DepartmentModel::rowAllowsCloud(null));
    }
}
