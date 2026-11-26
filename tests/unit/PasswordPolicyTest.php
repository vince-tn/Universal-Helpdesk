<?php

use App\Libraries\PasswordPolicy;
use CodeIgniter\Test\CIUnitTestCase;

final class PasswordPolicyTest extends CIUnitTestCase
{
    public function testAGoodPasswordPasses(): void
    {
        $this->assertNull(PasswordPolicy::check('blue Kettle river 42', 'blue Kettle river 42', 'jane@company.com'));
    }

    public function testShortPasswordsFail(): void
    {
        $this->assertNotNull(PasswordPolicy::check('Short1!abc', 'Short1!abc', 'jane@company.com'));
        $this->assertNull(PasswordPolicy::check('Exactly12ch!', 'Exactly12ch!', 'jane@company.com'));
    }

    public function testConfirmMustMatch(): void
    {
        $this->assertSame('The two new passwords do not match.', PasswordPolicy::check('blue Kettle river 42', 'blue Kettle river 43', 'jane@company.com'));
    }

    public function testEmailCannotBeThePassword(): void
    {
        $this->assertNotNull(PasswordPolicy::check('Jane.Doe@Company.com', 'Jane.Doe@Company.com', 'jane.doe@company.com'));
        $this->assertNotNull(PasswordPolicy::check('jane.doe.smith', 'jane.doe.smith', 'jane.doe.smith@company.com'));
    }

    public function testCommonPasswordsFail(): void
    {
        $this->assertNotNull(PasswordPolicy::check('Password1234', 'Password1234', 'jane@company.com'));
        $this->assertNotNull(PasswordPolicy::check('aaaaaaaaaaaa', 'aaaaaaaaaaaa', 'jane@company.com'));
        $this->assertNotNull(PasswordPolicy::check('abababababab', 'abababababab', 'jane@company.com'));
    }

    public function testNewPasswordMustDifferFromCurrent(): void
    {
        $hash = password_hash('blue Kettle river 42', PASSWORD_DEFAULT);

        $this->assertSame('Pick a password different from your current one.', PasswordPolicy::check('blue Kettle river 42', 'blue Kettle river 42', 'jane@company.com', $hash));
        $this->assertNull(PasswordPolicy::check('green Teapot lake 17', 'green Teapot lake 17', 'jane@company.com', $hash));
    }
}
