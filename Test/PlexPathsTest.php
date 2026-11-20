<?php

use Kanboard\Plugin\Matinee\Http\PlexPaths;
use PHPUnit\Framework\TestCase;

class PlexPathsTest extends TestCase
{
    /** @return array<string, array{string, bool, bool}> [path, isBrowse, isArt] */
    public static function paths(): array
    {
        $twenty = str_repeat('9', 20);
        $twentyOne = str_repeat('9', 21);
        return [
            'section all' => ['/library/sections/2/all', true, false],
            'section all, 20 digits' => ["/library/sections/$twenty/all", true, false],
            'children' => ['/library/metadata/123/children', true, false],
            'thumb' => ['/library/metadata/123/thumb/1700000000', false, true],
            'art' => ['/library/metadata/123/art/1700000000', false, true],
            'art, 20-digit ids' => ["/library/metadata/$twenty/art/$twenty", false, true],

            'empty' => ['', false, false],
            'root' => ['/', false, false],
            'section all, 21 digits' => ["/library/sections/$twentyOne/all", false, false],
            'thumb, 21-digit id' => ["/library/metadata/$twentyOne/thumb/1", false, false],
            'thumb, 21-digit stamp' => ["/library/metadata/1/thumb/$twentyOne", false, false],
            'negative section' => ['/library/sections/-2/all', false, false],
            'negative metadata' => ['/library/metadata/-1/children', false, false],
            'negative thumb' => ['/library/metadata/1/thumb/-1', false, false],
            'empty id' => ['/library/sections//all', false, false],
            'dot-dot' => ['/library/sections/2/../../all', false, false],
            'dot-dot in art' => ['/library/metadata/1/thumb/../../../identity', false, false],
            'dot-dot after' => ['/library/metadata/1/children/..', false, false],
            'encoded dot' => ['/library/sections/%2e%2e/all', false, false],
            'encoded dot in art' => ['/library/metadata/1/%2e%2e/1', false, false],
            'encoded slash' => ['/library/metadata/1%2fchildren', false, false],
            'query' => ['/library/sections/2/all?x', false, false],
            'query in art' => ['/library/metadata/1/thumb/2?X-Plex-Token=x', false, false],
            'fragment' => ['/library/metadata/1/children#x', false, false],
            'double slash' => ['//library/sections/2/all', false, false],
            'inner double slash' => ['/library//metadata/1/children', false, false],
            'double slash in art' => ['/library/metadata/1//thumb/2', false, false],
            'trailing slash' => ['/library/sections/2/all/', false, false],
            'trailing slash art' => ['/library/metadata/1/thumb/2/', false, false],
            'trailing newline' => ["/library/sections/2/all\n", false, false],
            'trailing newline art' => ["/library/metadata/1/art/2\n", false, false],
            'leading space' => [' /library/sections/2/all', false, false],
            'no leading slash' => ['library/sections/2/all', false, false],
            'at sign' => ['@evil.test/library/sections/2/all', false, false],
            'absolute url' => ['http://evil.test/library/sections/2/all', false, false],
            'backslash' => ['\\library\\sections\\2\\all', false, false],
            'nul byte' => ["/library/sections/2/all\0", false, false],
            'unicode digit' => ["/library/sections/\u{0662}/all", false, false],
            'upper case' => ['/library/Sections/2/all', false, false],
            'metadata item' => ['/library/metadata/1', false, false],
            'sections list' => ['/library/sections', false, false],
            'other kind' => ['/library/metadata/1/banner/2', false, false],
            'part' => ['/library/parts/1/2/file.mp4', false, false],
            'transcode' => ['/photo/:/transcode', false, false],
        ];
    }

    /** @dataProvider paths */
    public function testPathPredicates(string $path, bool $browse, bool $art): void
    {
        $this->assertSame($browse, PlexPaths::isBrowse($path), 'isBrowse');
        $this->assertSame($art, PlexPaths::isArt($path), 'isArt');
    }

    /** @return array<string, array{string, bool}> */
    public static function sectionKeys(): array
    {
        return [
            'one digit' => ['2', true],
            'zero' => ['0', true],
            '20 digits' => [str_repeat('1', 20), true],
            '21 digits' => [str_repeat('1', 21), false],
            'empty' => ['', false],
            'negative' => ['-2', false],
            'plus' => ['+2', false],
            'float' => ['2.0', false],
            'hex' => ['0x2', false],
            'space' => [' 2', false],
            'trailing newline' => ["2\n", false],
            'slash' => ['2/all', false],
            'dot-dot' => ['..', false],
            'encoded' => ['%32', false],
            'unicode digit' => ["\u{0662}", false],
        ];
    }

    /** @dataProvider sectionKeys */
    public function testIsSectionKey(string $key, bool $ok): void
    {
        $this->assertSame($ok, PlexPaths::isSectionKey($key));
    }

    public function testSectionAllBuildsABrowsePath(): void
    {
        $this->assertSame('/library/sections/12/all', PlexPaths::sectionAll('12'));
        $this->assertTrue(PlexPaths::isBrowse(PlexPaths::sectionAll('12')));
    }

    /** @dataProvider sectionKeys */
    public function testSectionAllRefusesABadKey(string $key, bool $ok): void
    {
        if ($ok) {
            $this->assertTrue(PlexPaths::isBrowse(PlexPaths::sectionAll($key)));
            return;
        }
        try {
            PlexPaths::sectionAll($key);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('plex paths: bad section key', $e->getMessage());
        }
    }
}
