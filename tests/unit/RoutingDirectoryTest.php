<?php

use App\Libraries\RoutingDirectory;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RoutingDirectoryTest extends CIUnitTestCase
{
    private function entries(): array
    {
        $hr = ['department' => 'HR', 'department_handles' => 'people matters'];
        $it = ['department' => 'Admin', 'department_handles' => 'IT support, computers, accounts'];

        return [
            $hr + ['role' => '', 'handles' => ''],
            $hr + ['role' => 'Employee Relations', 'handles' => 'leave, sick leave, vacation, attendance'],
            $hr + ['role' => 'Payroll', 'handles' => 'salary, payslips, deductions'],
            $hr + ['role' => 'Recruitment', 'handles' => 'hiring, job openings, interviews'],
            $it + ['role' => '', 'handles' => ''],
            $it + ['role' => 'IT Staff', 'handles' => 'laptops, passwords, email, printers'],
        ];
    }

    public function testTheRequestersPickIsAlwaysOptionOne(): void
    {
        $options = RoutingDirectory::rank($this->entries(), 'I want to file a sick leave for yesterday', 'HR', 'Payroll');

        $this->assertSame('HR / Payroll', RoutingDirectory::label($options[0]));
    }

    public function testSickLeaveFiledUnderPayrollFindsEmployeeRelations(): void
    {
        $options = RoutingDirectory::rank($this->entries(), 'I want to file a sick leave for yesterday', 'HR', 'Payroll');

        $this->assertSame('HR / Employee Relations', RoutingDirectory::label($options[1]));
    }

    public function testPluralsMatchAcrossDepartments(): void
    {
        $options = RoutingDirectory::rank($this->entries(), 'my payslips are missing deductions', 'Admin', 'IT Staff');

        $this->assertSame('HR / Payroll', RoutingDirectory::label($options[1]));
    }

    public function testNoSharedWordsMeansNoAlternatives(): void
    {
        $this->assertCount(1, RoutingDirectory::rank($this->entries(), 'zebra quantum', 'HR', 'Payroll'));
    }

    public function testNeverMoreThanTheLimit(): void
    {
        $options = RoutingDirectory::rank($this->entries(), 'leave salary hiring laptops passwords people', 'HR', '');

        $this->assertLessThanOrEqual(RoutingDirectory::LIMIT, count($options));
        $this->assertSame('HR', RoutingDirectory::label($options[0]));
    }

    public function testAnUnknownPickIsStillOptionOne(): void
    {
        $options = RoutingDirectory::rank($this->entries(), 'sick leave', 'Old Department', '');

        $this->assertSame('Old Department', $options[0]['department']);
    }

    public function testSickLeaveUnderPayrollMovesWhenTheModelAndTheWordsAgree(): void
    {
        $request = 'I want to file a sick leave for yesterday';
        $options = RoutingDirectory::rank($this->entries(), $request, 'HR', 'Payroll');
        $route   = (new RoutingDirectory())->decide($options, ['routed_team' => 'Employee Relations'], $request);

        $this->assertSame('HR / Employee Relations', $route['to']);
        $this->assertSame('HR / Payroll', $route['from']);
        $this->assertSame('Employee Relations', $route['role']);
    }

    public function testAWrongModelAnswerIsOverruledByTheWords(): void
    {
        // A real mistake from testing: qwen2.5:3b sent a vacation request to Payroll.
        $request = 'I would like to file vacation leave from October 22 to 25';
        $options = RoutingDirectory::rank($this->entries(), $request, 'HR', 'Employee Relations');

        $this->assertNull((new RoutingDirectory())->decide($options, ['routed_team' => 'Payroll'], $request));
    }

    public function testNothingMovesWithoutAClearAnswer(): void
    {
        $routing = new RoutingDirectory();
        $request = 'I want to file a sick leave for yesterday';
        $options = RoutingDirectory::rank($this->entries(), $request, 'HR', 'Payroll');

        $this->assertNull($routing->decide($options, ['routed_team' => ''], $request));
        $this->assertNull($routing->decide($options, ['routed_team' => 'Payroll'], $request), 'the requester\'s own pick');
        $this->assertNull($routing->decide($options, ['routed_team' => 'Facilities'], $request), 'a team that was not offered');
        $this->assertNull($routing->decide($options, [], $request));
    }

    public function testNamedPrefersTheLongestMatchingName(): void
    {
        $options = RoutingDirectory::rank($this->entries(), 'sick leave salary', 'HR', '');

        $this->assertSame('Payroll', RoutingDirectory::named('HR / Payroll', $options)['role']);
        $this->assertSame('Employee Relations', RoutingDirectory::named('Employee Relations.', $options)['role']);
        $this->assertSame('Employee Relations', RoutingDirectory::named('Employee Relations (HR)', $options)['role'], 'a role beats its department');
        $this->assertSame('', RoutingDirectory::named('HR', $options)['role']);
        $this->assertNull(RoutingDirectory::named('Payroll or Employee Relations', $options), 'two teams named is unclear');
        $this->assertNull(RoutingDirectory::named('three payrolls', $options), 'whole words only');
    }

    public function testPromptListsTeamsAlphabeticallyWithoutMarkingThePick(): void
    {
        $options = RoutingDirectory::rank($this->entries(), 'sick leave', 'HR', 'Payroll');
        $prompt  = (new RoutingDirectory())->prompt('I want to file a sick leave', $options);

        $this->assertStringContainsString('Request: I want to file a sick leave', $prompt);
        $this->assertLessThan(strpos($prompt, 'HR / Payroll'), strpos($prompt, 'HR / Employee Relations'));
        $this->assertStringNotContainsString('picked', $prompt);
    }
}
