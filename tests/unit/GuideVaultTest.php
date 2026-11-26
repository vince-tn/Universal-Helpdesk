<?php

use App\Libraries\GuideVault;
use CodeIgniter\Test\CIUnitTestCase;

final class GuideVaultTest extends CIUnitTestCase
{
    private function article(array $over = []): array
    {
        return $over + [
            'id'         => 12,
            'title'      => 'VPN keeps disconnecting',
            'keywords'   => 'remote access, forticlient',
            'body'       => "Open FortiClient.\nReconnect.",
            'is_active'  => 1,
            'updated_at' => '2026-03-03 09:30:00',
        ];
    }

    public function testGlobalGuidesGetTheirOwnFolder(): void
    {
        $this->assertSame(GuideVault::GLOBAL_FOLDER, GuideVault::folder(null));
        $this->assertSame(GuideVault::GLOBAL_FOLDER, GuideVault::folder('   '));
        $this->assertSame('Compliance HQ', GuideVault::folder('Compliance HQ'));
    }

    public function testAFolderNameCannotEscapeTheVault(): void
    {
        $this->assertSame('etc-passwd', GuideVault::folder('../etc/passwd'));
        $this->assertSame('Reports-WFM', GuideVault::folder('Reports/WFM'));
        $this->assertSame(GuideVault::UNKNOWN_FOLDER, GuideVault::folder('///'));
    }

    public function testFileNamesAreStableAndCarryTheId(): void
    {
        $this->assertSame('vpn-keeps-disconnecting-12.md', GuideVault::fileName($this->article()));
        $this->assertSame('guide-9.md', GuideVault::fileName(['id' => 9, 'title' => '***']));
        $this->assertSame(60 + strlen('-3.md'), strlen(GuideVault::fileName([
            'id'    => 3,
            'title' => str_repeat('long title ', 20),
        ])));
    }

    public function testOnlyExportedFilesAreTreatedAsGenerated(): void
    {
        $this->assertTrue(GuideVault::isGenerated('vpn-keeps-disconnecting-12.md'));
        $this->assertFalse(GuideVault::isGenerated('README.md'));
        $this->assertFalse(GuideVault::isGenerated('my own notes.md'));
    }

    public function testTheNoteCarriesTheArticleIdentityInFrontMatter(): void
    {
        $note = GuideVault::note($this->article(), 'IT');

        $this->assertStringStartsWith("---\nuid: 12\n", $note);
        $this->assertStringContainsString("title: 'VPN keeps disconnecting'", $note);
        $this->assertStringContainsString("department: 'IT'", $note);
        $this->assertStringContainsString('active: true', $note);
        $this->assertStringContainsString("updated: '2026-03-03 09:30:00'", $note);
        $this->assertStringContainsString("keywords:\n  - 'remote access'\n  - 'forticlient'", $note);
        $this->assertStringContainsString("# VPN keeps disconnecting\n\nOpen FortiClient.\nReconnect.\n", $note);
    }

    public function testAnApostropheCannotBreakTheFrontMatter(): void
    {
        $note = GuideVault::note($this->article(['title' => "Jim's laptop"]), "Ops 'A'");

        $this->assertStringContainsString("title: 'Jim''s laptop'", $note);
        $this->assertStringContainsString("department: 'Ops ''A'''", $note);
    }

    public function testAnArticleOutOfUseIsMarkedNotActive(): void
    {
        $this->assertStringContainsString('active: false', GuideVault::note($this->article(['is_active' => 0]), 'IT'));
    }

    public function testNoKeywordsMeansNoKeywordsBlock(): void
    {
        $this->assertStringNotContainsString('keywords:', GuideVault::note($this->article(['keywords' => ' , ']), 'IT'));
    }

    public function testWindowsLineEndingsAreNormalised(): void
    {
        $note = GuideVault::note($this->article(['body' => "One.\r\nTwo.\r"]), 'IT');

        $this->assertStringContainsString("One.\nTwo.", $note);
        $this->assertStringNotContainsString("\r", $note);
    }
}
