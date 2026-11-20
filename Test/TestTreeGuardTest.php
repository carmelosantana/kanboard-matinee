<?php

use PHPUnit\Framework\TestCase;

/**
 * A plugin installed from git ships Test/ under the web root, and a stock nginx runs any *.php it is asked for. Every
 * PHP file under Test/ that is not a *Test.php class therefore starts (after an optional declare and namespace) with
 * the SAPI guard below, so over the web it answers 404 and does nothing; Apache is refused by Test/.htaccess as well.
 */
class TestTreeGuardTest extends TestCase
{
    private const GUARD = "if(!in_array(PHP_SAPI,['cli','cli-server'],true)){http_response_code(404);exit;}";

    /** @return list<string> the PHP files under Test/ that are not test classes */
    private static function scripts(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, '.php') && ! str_ends_with($path, 'Test.php')) {
                $out[] = $path;
            }
        }
        sort($out);
        return $out;
    }

    /** The code of $source after `<?php` and any declare/namespace statements, with whitespace and comments removed. */
    public static function leadingCode(string $source, int $length): string
    {
        $tokens = token_get_all($source);
        if (($tokens[0][0] ?? null) !== T_OPEN_TAG) {
            return '';
        }
        $code = [];
        foreach (array_slice($tokens, 1) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code[] = is_array($t) ? $t[1] : $t;
        }
        // Skip leading `declare(...);` and `namespace Name;` statements.
        while (in_array(strtolower($code[0] ?? ''), ['declare', 'namespace'], true)) {
            $end = array_search(';', $code, true);
            if ($end === false) {
                return '';
            }
            $code = array_slice($code, $end + 1);
        }
        return substr(implode('', $code), 0, $length);
    }

    public function testEveryNonTestScriptStartsWithTheSapiGuard(): void
    {
        $scripts = self::scripts();
        $this->assertNotEmpty($scripts);
        foreach ($scripts as $path) {
            $this->assertSame(self::GUARD, self::leadingCode((string) file_get_contents($path), strlen(self::GUARD)), substr($path, strlen(__DIR__)));
        }
    }

    public function testTheGuardCheckSeesThroughDeclareNamespaceAndComments(): void
    {
        $guard = "if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }";
        $this->assertSame(self::GUARD, self::leadingCode("<?php\n// note\ndeclare(strict_types=1);\nnamespace A\\B;\n$guard\necho 1;", strlen(self::GUARD)));
        $this->assertNotSame(self::GUARD, self::leadingCode("<?php\necho 1;\n$guard", strlen(self::GUARD)));
        $this->assertNotSame(self::GUARD, self::leadingCode("<?php\nuse A\\B;\n$guard", strlen(self::GUARD)));
        $this->assertNotSame(self::GUARD, self::leadingCode("<html><?php $guard", strlen(self::GUARD)));
    }

    public function testApacheIsRefusedTheWholeTestTree(): void
    {
        $this->assertFileExists(__DIR__.'/.htaccess');
        $this->assertMatchesRegularExpression('/^\s*Require all denied\s*$/m', (string) file_get_contents(__DIR__.'/.htaccess'));
    }
}
