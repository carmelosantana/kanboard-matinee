<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;

/**
 * The vendored hls.js (plan P3 Task 16; spec §7 Player). The README's "Vendored files" row is the single source of
 * truth for the sha256: the file on disk must hash to it, byte for byte.
 */
class VendorTest extends Base
{
    private const DIR = __DIR__.'/../Assets/vendor/hls.js';

    private function readmeRow(string $file): array
    {
        $readme = (string) file_get_contents(__DIR__.'/../README.md');
        $section = strstr($readme, "\n## Vendored files");
        $this->assertNotFalse($section, 'README has a "Vendored files" section');
        foreach (explode("\n", $section) as $line) {
            $cells = array_map(fn ($c) => trim($c, " \t`"), explode('|', trim($line)));
            if (count($cells) > 2 && $cells[1] === $file) {
                return array_slice($cells, 1, -1);
            }
        }
        $this->fail('README "Vendored files" has a row for '.$file);
    }

    public function testHlsMinJsMatchesTheReadmeSha256(): void
    {
        $row = $this->readmeRow('Assets/vendor/hls.js/hls.min.js');
        $sha = array_values(array_filter($row, fn ($c) => preg_match('/\A[0-9a-f]{64}\z/', $c) === 1));
        $this->assertCount(1, $sha, 'the row records exactly one sha256');
        $this->assertFileExists(self::DIR.'/hls.min.js');
        $this->assertSame($sha[0], hash_file('sha256', self::DIR.'/hls.min.js'));
        $this->assertContains('1.7.1', $row);
        $this->assertContains('https://registry.npmjs.org/hls.js/-/hls.js-1.7.1.tgz', $row);
    }

    public function testHlsMinJsIsVersion171(): void
    {
        $this->assertFileExists(self::DIR.'/hls.min.js');
        $this->assertMatchesRegularExpression('/["\']1\.7\.1["\']/', (string) file_get_contents(self::DIR.'/hls.min.js'));
    }

    public function testLicenseIsApache(): void
    {
        $this->assertFileExists(self::DIR.'/LICENSE');
        $this->assertStringContainsString('Apache License', (string) file_get_contents(self::DIR.'/LICENSE'));
    }
}
