<?php

use Kanboard\Plugin\Matinee\Http\HlsPlaylist;
use Kanboard\Plugin\Matinee\Http\PlexException;
use PHPUnit\Framework\TestCase;

/**
 * HlsPlaylist (spec §7 Video, S2; P3 C2d, C2e): every URI in a Plex playlist, bare or in a tag's URI="…", becomes a
 * Matinee proxy URL from the injected builder, and anything off the session's S2 shape fails the whole playlist.
 */
class HlsPlaylistTest extends TestCase
{
    private const UUID = '0f1e2d3c-4b5a-4987-8a6b-5c4d3e2f1a0b';
    private const OTHER = '11111111-2222-4333-8444-555566667777';
    private const START = '/video/:/transcode/universal/start.m3u8';
    private const BASE = '/video/:/transcode/universal/session/' . self::UUID . '/base';

    private static function rewrite(string $body, string $upstream = self::BASE . '/index.m3u8'): string
    {
        return HlsPlaylist::rewrite($body, $upstream, self::UUID, static fn (string $u): string => 'P(' . $u . ')');
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/plex/' . $name . '.m3u8');
    }

    public function testTheMasterFixtureRewritesExactly(): void
    {
        $this->assertSame(
            "#EXTM3U\n#EXT-X-VERSION:3\n"
                . "#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH=8000000,RESOLUTION=1920x1080,CODECS=\"avc1.640028,mp4a.40.2\"\n"
                . 'P(' . self::BASE . "/index.m3u8)\n",
            self::rewrite(self::fixture('hls-master'), self::START),
        );
    }

    public function testTheIndexFixtureRewritesItsRelativeSegments(): void
    {
        $this->assertSame(
            "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:2\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-PLAYLIST-TYPE:VOD\n"
                . "#EXTINF:2.002000,\nP(" . self::BASE . "/00000.ts)\n"
                . "#EXTINF:2.002000,\nP(" . self::BASE . "/00001.ts)\n"
                . "#EXTINF:1.501000,\nP(" . self::BASE . "/00002.ts?x=1)\n"
                . "\n#EXT-X-ENDLIST\n",
            self::rewrite(self::fixture('hls-index')),
        );
    }

    public function testTagUrisAreRewrittenAndTheRestOfTheTagIsKept(): void
    {
        $body = "#EXTM3U\n"
            . "#EXT-X-KEY:METHOD=AES-128,URI=\"key.aac\",IV=0x00\n"
            . "#EXT-X-MAP:URI=\"init.m4s\"\n"
            . "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subs\",NAME=\"English\",URI=\"subs.m3u8\"\n"
            . "#EXT-X-FOO:URI=\"a.vtt\",X=1,URI=\"b.vtt\"\n";
        $this->assertSame(
            "#EXTM3U\n"
                . '#EXT-X-KEY:METHOD=AES-128,URI="P(' . self::BASE . "/key.aac)\",IV=0x00\n"
                . '#EXT-X-MAP:URI="P(' . self::BASE . "/init.m4s)\"\n"
                . '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="English",URI="P(' . self::BASE . "/subs.m3u8)\"\n"
                . '#EXT-X-FOO:URI="P(' . self::BASE . '/a.vtt)",X=1,URI="P(' . self::BASE . "/b.vtt)\"\n",
            self::rewrite($body),
        );
    }

    public function testAPathAbsoluteUriIsKeptAsItIs(): void
    {
        $this->assertSame(
            "#EXTM3U\nP(/video/:/transcode/universal/session/" . self::UUID . "/other/00009.ts)",
            self::rewrite("#EXTM3U\n/video/:/transcode/universal/session/" . self::UUID . '/other/00009.ts'),
        );
    }

    public function testARelativeUriJoinsTheUpstreamPathWithoutItsQuery(): void
    {
        $this->assertSame(
            "#EXTM3U\nP(" . self::BASE . '/00000.ts)',
            self::rewrite("#EXTM3U\n00000.ts", self::BASE . '/index.m3u8?a=b.c'),
        );
    }

    public function testCrlfRewritesLikeLf(): void
    {
        $lf = self::fixture('hls-index');
        $this->assertSame(self::rewrite($lf), self::rewrite(str_replace("\n", "\r\n", $lf)));
    }

    public function testTheBuilderGetsExactlyTheResolvedPathOncePerUri(): void
    {
        $seen = [];
        HlsPlaylist::rewrite(self::fixture('hls-index'), self::BASE . '/index.m3u8', self::UUID, function (string $u) use (&$seen): string {
            $seen[] = $u;
            return 'x';
        });
        $this->assertSame([self::BASE . '/00000.ts', self::BASE . '/00001.ts', self::BASE . '/00002.ts?x=1'], $seen);
    }

    /** @dataProvider badPlaylists */
    public function testAnUnsafePlaylistFailsWhole(string $body): void
    {
        try {
            self::rewrite($body);
            $this->fail('expected plex.bad_playlist');
        } catch (PlexException $e) {
            $this->assertSame(PlexException::BAD_PLAYLIST, $e->reason);
            $this->assertSame('plex.bad_playlist', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    /** @return array<string, array{string}> */
    public function badPlaylists(): array
    {
        $ok = "#EXTM3U\n#EXTINF:2.0,\n00000.ts\n";
        return [
            'absolute http'             => [$ok . "http://evil/x.ts\n"],
            'protocol-relative'         => [$ok . "//evil/x.ts\n"],
            'upper-case scheme'         => [$ok . "HTTPS://x\n"],
            'scheme without slashes'    => [$ok . "https:evil.test\n"],
            'parent dirs'               => [$ok . "../../library/x.ts\n"],
            // Normalising ".." would land back inside the session; it is refused all the same.
            'parent dir back inside'    => [$ok . "../base/00003.ts\n"],
            'encoded dot'               => [$ok . "a%2e.ts\n"],
            'token query'               => [$ok . "x.ts?X-Plex-Token=t\n"],
            'another session, absolute' => ["#EXTM3U\n/video/:/transcode/universal/session/" . self::OTHER . "/base/00000.ts\n"],
            'another session in a tag'  => ["#EXTM3U\n#EXT-X-MAP:URI=\"/video/:/transcode/universal/session/" . self::OTHER . "/base/init.m4s\"\n"],
            'not S2: mp4 map'           => ["#EXTM3U\n#EXT-X-MAP:URI=\"init.mp4\"\n"],
            'not S2: off the prefix'    => [$ok . "/library/parts/1/2/file.ts\n"],
            'lower-case uri attribute'  => ["#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,uri=\"http://evil/k\"\n"],
            'single-quoted uri'         => ["#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI='k/x.aac'\n"],
            'slash in another attr'     => ["#EXTM3U\n#EXT-X-FOO:X=\"/library/x\"\n"],
            'backslash in a tag'        => ["#EXTM3U\n#EXT-X-FOO:X=\"a\\b\"\n"],
            'bad uri in a tag'          => ["#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"https://evil/k\"\n"],
            'empty uri in a tag'        => ["#EXTM3U\n#EXT-X-MAP:URI=\"\"\n"],
            'control char in a tag'     => ["#EXTM3U\n#EXTINF:2.0,\rhttps:evil.test\n00000.ts\n"],
            'space in a uri line'       => [$ok . " 00001.ts\n"],
            'no EXTM3U'                 => ["#EXT-X-VERSION:3\n00000.ts\n"],
            'EXTM3U not first'          => ["\n#EXTM3U\n00000.ts\n"],
            'BOM before EXTM3U'         => ["\xEF\xBB\xBF#EXTM3U\n00000.ts\n"],
            'empty body'                => [''],
            'invalid utf-8'             => ["#EXTM3U\n#EXTINF:2.0,\xC3\x28\n00000.ts\n"],
        ];
    }
}
