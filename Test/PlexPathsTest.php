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

    // ── playback shapes (spec §6, S2; C5) ────────────────────────────────────

    private const UUID = '0f8e2b7c-3d4a-4b5c-9d6e-7f8091a2b3c4';

    /** @return array<string, array{string, bool, bool}> [path, isPart, isItem] */
    public static function playPaths(): array
    {
        $twenty = str_repeat('9', 20);
        $twentyOne = str_repeat('9', 21);
        return [
            'part, avi' => ['/library/parts/2229/1250471640/file.avi', true, false],
            'part, flac' => ['/library/parts/1/2/file.flac', true, false],
            'part, 20-digit ids' => ["/library/parts/$twenty/$twenty/file.mp3", true, false],
            'part, 8-char extension' => ['/library/parts/1/2/file.abcdefgh', true, false],
            'part, digit extension' => ['/library/parts/1/2/file.mp4', true, false],
            'item' => ['/library/metadata/42', false, true],
            'item, 20 digits' => ["/library/metadata/$twenty", false, true],

            'part, query' => ['/library/parts/1/2/file.mp3?x', false, false],
            'part, dot-dot' => ['/library/parts/1/2/../file.mp3', false, false],
            'part, upper case name' => ['/library/parts/1/2/FILE.mp3', false, false],
            'part, upper case extension' => ['/library/parts/1/2/file.MP3', false, false],
            'part, empty extension' => ['/library/parts/1/2/file.', false, false],
            'part, 9-char extension' => ['/library/parts/1/2/file.abcdefghi', false, false],
            'part, 21-digit id' => ["/library/parts/$twentyOne/2/file.mp3", false, false],
            'part, 21-digit second id' => ["/library/parts/1/$twentyOne/file.mp3", false, false],
            'part, other name' => ['/library/parts/1/2/other.mp3', false, false],
            'part, one id' => ['/library/parts/1/file.mp3', false, false],
            'part, trailing newline' => ["/library/parts/1/2/file.mp3\n", false, false],
            'part, trailing slash' => ['/library/parts/1/2/file.mp3/', false, false],
            'part, encoded dot' => ['/library/parts/1/2/file%2emp3', false, false],
            'part, double slash' => ['/library/parts/1//2/file.mp3', false, false],
            'part, absolute url' => ['http://evil.test/library/parts/1/2/file.mp3', false, false],
            'item, children' => ['/library/metadata/42/children', false, false],
            'item, negative' => ['/library/metadata/-1', false, false],
            'item, trailing slash' => ['/library/metadata/42/', false, false],
            'item, trailing newline' => ["/library/metadata/42\n", false, false],
            'item, 21 digits' => ["/library/metadata/$twentyOne", false, false],
            'item, empty' => ['/library/metadata/', false, false],
            'item, query' => ['/library/metadata/42?x', false, false],
            'item, art' => ['/library/metadata/42/thumb/1', false, false],
            'browse path' => ['/library/sections/2/all', false, false],
            'part, arabic-indic digit' => ["/library/parts/\u{0661}/2/file.mp3", false, false],
            'item, arabic-indic digit' => ["/library/metadata/\u{0661}", false, false],
            'empty' => ['', false, false],
        ];
    }

    /** @dataProvider playPaths */
    public function testPlayPathPredicates(string $path, bool $part, bool $item): void
    {
        $this->assertSame($part, PlexPaths::isPart($path), 'isPart');
        $this->assertSame($item, PlexPaths::isItem($path), 'isItem');
        $this->assertFalse(PlexPaths::isBrowse($path) && ($part || $item), 'no overlap with browse');
    }

    public function testItemBuildsAnItemPath(): void
    {
        $this->assertSame('/library/metadata/42', PlexPaths::item('42'));
        $this->assertTrue(PlexPaths::isItem(PlexPaths::item('42')));
    }

    /** @return array<string, array{string}> */
    public static function badRatingKeys(): array
    {
        return [
            'empty' => [''],
            'negative' => ['-1'],
            'slash' => ['42/children'],
            'dot-dot' => ['..'],
            '21 digits' => [str_repeat('1', 21)],
            'trailing newline' => ["42\n"],
            'letters' => ['abc'],
        ];
    }

    /** @dataProvider badRatingKeys */
    public function testItemRefusesABadKey(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('plex paths: bad rating key');
        PlexPaths::item($key);
    }

    /** @return array<string, array{string, bool}> */
    public static function hlsChildren(): array
    {
        $base = '/video/:/transcode/universal/session/'.self::UUID.'/base';
        return [
            'index' => ["$base/index.m3u8", true],
            'segment' => ["$base/00001.ts", true],
            'segment with query' => ["$base/00001.ts?x=1&y=a.b", true],
            'm4s' => ["$base/00001.m4s", true],
            'vtt' => ["$base/sub.vtt", true],
            'aac' => ["$base/00001.aac", true],
            'empty query' => ["$base/00001.ts?", true],
            '512-char query' => ["$base/00001.ts?".str_repeat('a', 512), true],

            'dot-dot in path' => ["$base/../index.m3u8", false],
            'dot-dot in query' => ["$base/00001.ts?x=..", false],
            'dot-dot in name' => ["$base/a..b.ts", false],
            'encoded dot' => ["$base/%2e%2e/index.m3u8", false],
            'percent in query' => ["$base/00001.ts?x=%41", false],
            'at sign' => ["$base/a@b.ts", false],
            'backslash' => ["$base\\index.m3u8", false],
            'double slash' => ["$base//index.m3u8", false],
            'double slash after session' => ['/video/:/transcode/universal/session/'.self::UUID.'//index.m3u8', false],
            'token query' => ["$base/00001.ts?X-Plex-Token=abc", false],
            'token query after another pair' => ["$base/00001.ts?x=1&X-Plex-Token=abc", false],
            'token-like key' => ["$base/00001.ts?x_plex_token=abc", false],
            'mp4' => ["$base/00001.mp4", false],
            'no extension' => ["$base/00001", false],
            'no session segment' => ['/video/:/transcode/universal/base/index.m3u8', false],
            'no session id' => ['/video/:/transcode/universal/session/index.m3u8', false],
            '35-char session' => ['/video/:/transcode/universal/session/'.substr(self::UUID, 0, 35).'/base/index.m3u8', false],
            '37-char session' => ['/video/:/transcode/universal/session/'.self::UUID.'a/base/index.m3u8', false],
            'upper case session' => ['/video/:/transcode/universal/session/'.strtoupper(self::UUID).'/base/index.m3u8', false],
            'nothing after the session' => ['/video/:/transcode/universal/session/'.self::UUID.'/', false],
            '513-char query' => ["$base/00001.ts?".str_repeat('a', 513), false],
            'absolute url' => ["http://evil/video/:/transcode/universal/session/".self::UUID.'/base/index.m3u8', false],
            'protocol-relative' => ['//evil/video/:/transcode/universal/session/'.self::UUID.'/base/index.m3u8', false],
            'trailing newline' => ["$base/index.m3u8\n", false],
            'fragment' => ["$base/index.m3u8#x", false],
            'space' => ["$base/index .m3u8", false],
            // Ruling: no query key that reads as an X-Plex header, so a viewer cannot relabel their own requests.
            'client identifier key' => ["$base/00001.ts?X-Plex-Client-Identifier=evil", false],
            'device name key' => ["$base/00001.ts?x=1&X-Plex-Device-Name=evil", false],
            'lower-case underscored key' => ["$base/00001.ts?x_plex_product=a", false],
            'upper-case run-together key' => ["$base/index.m3u8?XPLEXDEVICE=1", false],
            'mixed separators key' => ["$base/index.m3u8?x-PLEX_-_platform=1", false],
            'leading separator key' => ["$base/index.m3u8?_-x-plex=1", false],
            'bare xplex key' => ["$base/index.m3u8?xplex", false],
            'dotted key' => ["$base/00001.ts?X.Plex.Device.Name=evil", false],
            'dotted and dashed key' => ["$base/index.m3u8?a=1&x.plex-product_x=evil", false],
            'xplex key with no value' => ["$base/index.m3u8?a=1&X-Plex-Device-Name", false],
            'xplex in a value only' => ["$base/00001.ts?x=X-Plex-Device-Name", true],
            'plex key without x' => ["$base/00001.ts?plex-device=1", true],
            'key ending in xplex' => ["$base/00001.ts?a-x-plex=1", true],
            'start path' => ['/video/:/transcode/universal/start.m3u8', false],
            'other prefix' => ['/library/parts/1/2/file.ts', false],
            'token name in a value' => ["$base/00001.ts?x=XPlexToken", false],
            'token name in the path' => ["$base/X-Plex-Token.ts", false],
            '200-char path' => ['/video/:/transcode/universal/session/'.self::UUID.'/'.str_repeat('a', 200).'.ts', true],
            '201-char path' => ['/video/:/transcode/universal/session/'.self::UUID.'/'.str_repeat('a', 201).'.ts', false],
            'extension with a suffix' => ["$base/00001.tsx", false],
            'm3u8 with a suffix' => ["$base/index.m3u8x", false],
            'prefix before video' => ['/x/video/:/transcode/universal/session/'.self::UUID.'/base/index.m3u8', false],
            'host before video' => ['evil.test/video/:/transcode/universal/session/'.self::UUID.'/base/index.m3u8', false],
            'empty' => ['', false],
        ];
    }

    /** @dataProvider hlsChildren */
    public function testIsHlsChild(string $u, bool $ok): void
    {
        $this->assertSame($ok, PlexPaths::isHlsChild($u));
        $this->assertSame($ok ? self::UUID : null, PlexPaths::hlsSession($u));
    }

    /** @return array<string, array{string}> */
    public static function nonV4Sessions(): array
    {
        return [
            'v1' => ['0f8e2b7c-3d4a-1b5c-9d6e-7f8091a2b3c4'],
            '36 dashes' => [str_repeat('-', 36)],
        ];
    }

    // m5: S2's regex admits any 36-char [0-9a-f-] segment; hlsSession names only a v4 id Matinee could have minted.
    /** @dataProvider nonV4Sessions */
    public function testHlsSessionIsOnlyAV4Id(string $id): void
    {
        $u = '/video/:/transcode/universal/session/'.$id.'/base/index.m3u8';
        $this->assertTrue(PlexPaths::isHlsChild($u));
        $this->assertNull(PlexPaths::hlsSession($u));
    }

    public function testTheHlsPrefix(): void
    {
        $this->assertSame('/video/:/transcode/universal/', PlexPaths::HLS_PREFIX);
    }

    /** @return array<string, array{string, bool}> */
    public static function sessionIds(): array
    {
        return [
            'v4' => [self::UUID, true],
            'v4, variant a' => ['0f8e2b7c-3d4a-4b5c-ad6e-7f8091a2b3c4', true],
            'v4, variant b' => ['0f8e2b7c-3d4a-4b5c-bd6e-7f8091a2b3c4', true],
            'v4, variant 8' => ['0f8e2b7c-3d4a-4b5c-8d6e-7f8091a2b3c4', true],
            'v1' => ['0f8e2b7c-3d4a-1b5c-9d6e-7f8091a2b3c4', false],
            'variant c' => ['0f8e2b7c-3d4a-4b5c-cd6e-7f8091a2b3c4', false],
            'variant 7' => ['0f8e2b7c-3d4a-4b5c-7d6e-7f8091a2b3c4', false],
            'upper case' => [strtoupper(self::UUID), false],
            'no dashes' => [str_replace('-', '', self::UUID), false],
            '35 chars' => [substr(self::UUID, 0, 35), false],
            'trailing newline' => [self::UUID."\n", false],
            'braces' => ['{'.self::UUID.'}', false],
            'empty' => ['', false],
        ];
    }

    /** @dataProvider sessionIds */
    public function testIsSessionId(string $id, bool $ok): void
    {
        $this->assertSame($ok, PlexPaths::isSessionId($id));
    }
}
