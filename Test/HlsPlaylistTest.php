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
            . "#EXT-X-MAP:URI=\"init.m4s\"\n"
            . "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subs\",NAME=\"English\",DEFAULT=NO,URI=\"subs.m3u8\"\n"
            . "#EXT-X-MAP:URI=\"a.m4s\",BYTERANGE=\"10@0\",URI=\"b.m4s\"\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=1,CODECS=\"avc1.640028,mp4a.40.2\",RESOLUTION=1920x1080,\n"
            . "00000.m3u8\n";
        $this->assertSame(
            "#EXTM3U\n"
                . '#EXT-X-MAP:URI="P(' . self::BASE . "/init.m4s)\"\n"
                . '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="English",DEFAULT=NO,URI="P(' . self::BASE . "/subs.m3u8)\"\n"
                . '#EXT-X-MAP:URI="P(' . self::BASE . '/a.m4s)",BYTERANGE="10@0",URI="P(' . self::BASE . "/b.m4s)\"\n"
                . "#EXT-X-STREAM-INF:BANDWIDTH=1,CODECS=\"avc1.640028,mp4a.40.2\",RESOLUTION=1920x1080,\n"
                . 'P(' . self::BASE . "/00000.m3u8)\n",
            self::rewrite($body),
        );
    }

    public function testEveryAllowedTagPassesAsItIs(): void
    {
        $tags = "#EXT-X-VERSION:7\n#EXT-X-TARGETDURATION:2\n#EXT-X-MEDIA-SEQUENCE:12\n#EXT-X-PLAYLIST-TYPE:EVENT\n"
            . "#EXT-X-INDEPENDENT-SEGMENTS\n#EXT-X-PROGRAM-DATE-TIME:2026-11-21T10:00:00.000Z\n#EXT-X-DISCONTINUITY\n"
            . "#EXTINF:2.002000, nodesc\n";
        $this->assertSame(
            "#EXTM3U\n" . $tags . 'P(' . self::BASE . "/00000.ts)\n#EXT-X-ENDLIST",
            self::rewrite("#EXTM3U\n" . $tags . "00000.ts\n#EXT-X-ENDLIST"),
        );
    }

    public function testACommentLineIsDropped(): void
    {
        $this->assertSame(
            "#EXTM3U\n#EXTINF:2.0,\nP(" . self::BASE . "/00000.ts)\n",
            self::rewrite("#EXTM3U\n# made by Plex\n#EXTINF:2.0,\n#ext-x-key:URI=https:evil.test\n#\n00000.ts\n"),
        );
    }

    /** Veranda's rewrite test inputs (apps/server/src/hls), with a Matinee session id and without their X-Plex keys. */
    public function testVerandasPlaylistsRewrite(): void
    {
        $master = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH=8000000,RESOLUTION=1920x1080\n"
            . 'session/' . self::UUID . "/base/index.m3u8\n";
        $this->assertSame(
            "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH=8000000,RESOLUTION=1920x1080\n"
                . 'P(' . self::BASE . "/index.m3u8)\n",
            self::rewrite($master, self::START),
        );
        $media = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXT-X-MEDIA-SEQUENCE:0\n"
            . '#EXT-X-MAP:URI="session/' . self::UUID . "/base/header.ts\"\n"
            . "#EXTINF:10.000,\nsession/" . self::UUID . "/base/00000.ts\n"
            . "#EXTINF:10.000,\nsession/" . self::UUID . "/base/00001.ts\n#EXT-X-ENDLIST\n";
        $this->assertSame(
            "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXT-X-MEDIA-SEQUENCE:0\n"
                . '#EXT-X-MAP:URI="P(' . self::BASE . "/header.ts)\"\n"
                . "#EXTINF:10.000,\nP(" . self::BASE . "/00000.ts)\n"
                . "#EXTINF:10.000,\nP(" . self::BASE . "/00001.ts)\n#EXT-X-ENDLIST\n",
            self::rewrite($media, self::START),
        );
    }

    public function testFiftyThousandUrisPassAndOneMoreFails(): void
    {
        $this->assertSame(50000, HlsPlaylist::MAX_URIS);
        $body = "#EXTM3U\n" . str_repeat("00000.ts\n", HlsPlaylist::MAX_URIS - 1) . "#EXT-X-MAP:URI=\"init.m4s\"\n";
        $out = HlsPlaylist::rewrite($body, self::BASE . '/index.m3u8', self::UUID, static fn (string $u): string => 'x');
        $this->assertSame(HlsPlaylist::MAX_URIS, substr_count($out, "x\n") + substr_count($out, '"x"'));

        $this->assertBadPlaylist(static fn () => HlsPlaylist::rewrite(
            $body . "00000.ts\n", self::BASE . '/index.m3u8', self::UUID, static fn (string $u): string => 'x',
        ));
    }

    public function testSixteenMebibytesOfOutputPassAndOneByteMoreFails(): void
    {
        $this->assertSame(16 * 1024 * 1024, HlsPlaylist::MAX_BYTES);
        $fill = HlsPlaylist::MAX_BYTES - strlen("#EXTM3U\n");
        $this->assertSame(
            HlsPlaylist::MAX_BYTES,
            strlen(HlsPlaylist::rewrite("#EXTM3U\n00000.ts", self::BASE . '/index.m3u8', self::UUID, static fn (string $u): string => str_repeat('a', $fill))),
        );
        $this->assertBadPlaylist(static fn () => HlsPlaylist::rewrite(
            "#EXTM3U\n00000.ts", self::BASE . '/index.m3u8', self::UUID, static fn (string $u): string => str_repeat('a', $fill + 1),
        ));
        // The newline that joins the lines counts too.
        $this->assertBadPlaylist(static fn () => HlsPlaylist::rewrite(
            "#EXTM3U\n00000.ts\n", self::BASE . '/index.m3u8', self::UUID, static fn (string $u): string => str_repeat('a', $fill),
        ));
    }

    /** @dataProvider badProxyUrls */
    public function testABadProxyUrlFailsThePlaylist(string $url): void
    {
        $this->assertBadPlaylist(static fn () => HlsPlaylist::rewrite(
            "#EXTM3U\n00000.ts\n", self::BASE . '/index.m3u8', self::UUID, static fn (string $u): string => $url,
        ));
    }

    /** @return array<string, array{string}> */
    public function badProxyUrls(): array
    {
        return [
            'empty'     => [''],
            'quote'     => ['/kb/?u="x'],
            'space'     => ['/kb/?u= x'],
            'tab'       => ["/kb/?u=\tx"],
            'newline'   => ["/kb/?u=x\nhttps:evil.test"],
            'DEL'       => ["/kb/?u=x\x7f"],
            'non-ascii' => ["/kb/?u=x\u{2028}"],
        ];
    }

    private function assertBadPlaylist(\Closure $run): void
    {
        try {
            $run();
            $this->fail('expected plex.bad_playlist');
        } catch (PlexException $e) {
            $this->assertSame(PlexException::BAD_PLAYLIST, $e->reason);
        }
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
        $this->assertSame(
            "#EXTM3U\nP(" . self::BASE . '/00000.ts)',
            self::rewrite("#EXTM3U\n00000.ts", self::BASE . '/index.m3u8?a=b/c'),
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

    // ── L1: a line is at most MAX_LINE_BYTES and a tag at most MAX_ATTRIBUTES attributes ───────────────────

    public function testALineOfExactlyTheCapPasses(): void
    {
        $head = '#EXT-X-MEDIA:A="';
        $line = $head . str_repeat('a', HlsPlaylist::MAX_LINE_BYTES - strlen($head) - 1) . '"';
        $this->assertSame(8192, strlen($line));
        $this->assertSame("#EXTM3U\n" . $line, self::rewrite("#EXTM3U\n" . $line));
    }

    /** @dataProvider overlongLines */
    public function testALineOverTheCapFailsWhole(string $line): void
    {
        $this->assertSame(8193, strlen($line));
        $this->assertBadPlaylist(fn () => self::rewrite("#EXTM3U\n" . $line . "\n"));
    }

    /** @return array<string, array{string}> */
    public function overlongLines(): array
    {
        $head = '#EXT-X-MEDIA:A="';
        return [
            'attribute line' => [$head . str_repeat('a', 8192 - strlen($head)) . '"'],
            'bare uri'       => ['00000.ts?' . str_repeat('a', 8193 - 9)],
            'extinf title'   => ['#EXTINF:2.0,' . str_repeat('t', 8193 - 12)],
        ];
    }

    public function testATagWithExactlyTheAttributeCapPasses(): void
    {
        $list = implode(',', array_map(static fn (int $i): string => 'X' . $i . '=1', range(1, HlsPlaylist::MAX_ATTRIBUTES)));
        $this->assertSame(64, HlsPlaylist::MAX_ATTRIBUTES);
        $this->assertSame("#EXTM3U\n#EXT-X-MEDIA:" . $list, self::rewrite("#EXTM3U\n#EXT-X-MEDIA:" . $list));
    }

    public function testATagOverTheAttributeCapFailsWhole(): void
    {
        $list = implode(',', array_map(static fn (int $i): string => 'X' . $i . '=1', range(1, HlsPlaylist::MAX_ATTRIBUTES + 1)));
        $this->assertBadPlaylist(fn () => self::rewrite("#EXTM3U\n#EXT-X-MEDIA:" . $list));
    }

    /** The reviewer's probe: one 2 MiB attribute line of `A=,` used to allocate ~600 MB in preg_match_all. */
    public function testAHugeAttributeLineFailsWithoutAllocatingMuch(): void
    {
        $body = "#EXTM3U\n#EXT-X-MEDIA:" . str_repeat('A=,', 666667);
        $this->assertGreaterThan(2000000, strlen($body));
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $this->assertBadPlaylist(fn () => self::rewrite($body));
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before);
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
            // Review of Tasks 11-12: each of these got a URI past the rewrite into hls.js 1.7.4's M3U8Parser.
            'unquoted URI on KEY'       => ["#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=https:evil.test\n" . "#EXTINF:2.0,\n00000.ts\n"],
            'unquoted URI on MAP'       => ["#EXTM3U\n#EXT-X-MAP:URI=https:evil.test\n"],
            'unquoted data URI on MAP'  => ["#EXTM3U\n#EXT-X-MAP:URI=data:,AAAA\n"],
            'unquoted URI on MEDIA'     => ["#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"a\",NAME=\"x\",URI=https:evil.test\n"],
            'unquoted URI, then quoted' => ["#EXTM3U\n#EXT-X-MAP:URI=https:evil.test,X=\"init.m4s\"\n"],
            'quote glued to a value'    => ["#EXTM3U\n#EXT-X-MAP:URI=\"init.m4s\"x\n"],
            'unquoted URI on PRELOAD'   => ["#EXTM3U\n#EXT-X-PRELOAD-HINT:TYPE=PART,URI=https:evil.test\n"],
            'unquoted SESSION-DATA URI' => ["#EXTM3U\n#EXT-X-SESSION-DATA:DATA-ID=\"a\",URI=https:evil.test\n"],
            'CONTENT-STEERING'          => ["#EXTM3U\n#EXT-X-CONTENT-STEERING:SERVER-URI=https:evil.test\n"],
            'quoted CONTENT-STEERING'   => ["#EXTM3U\n#EXT-X-CONTENT-STEERING:SERVER-URI=\"x.m3u8\"\n"],
            'DATERANGE X-ASSET-LIST'    => ["#EXTM3U\n#EXT-X-DATERANGE:ID=\"a\",START-DATE=\"2026-01-01T00:00:00Z\",X-ASSET-LIST=\"https:evil.test\"\n"],
            'quoted PART'               => ["#EXTM3U\n#EXT-X-PART:DURATION=1,URI=\"p.ts\"\n"],
            'I-FRAME-STREAM-INF'        => ["#EXTM3U\n#EXT-X-I-FRAME-STREAM-INF:BANDWIDTH=1,URI=\"i.m3u8\"\n"],
            'RENDITION-REPORT'          => ["#EXTM3U\n#EXT-X-RENDITION-REPORT:URI=\"x.m3u8\",LAST-MSN=1\n"],
            'SESSION-KEY'               => ["#EXTM3U\n#EXT-X-SESSION-KEY:METHOD=AES-128,URI=\"k.aac\"\n"],
            'quoted KEY'                => ["#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"key.aac\"\n"],
            'START'                     => ["#EXTM3U\n#EXT-X-START:TIME-OFFSET=0\n"],
            'unknown EXT-X tag'         => ["#EXTM3U\n#EXT-X-FOO:URI=\"a.vtt\"\n"],
            'unknown EXT tag'           => ["#EXTM3U\n#EXTGRP:x\n"],
            'second EXTM3U'             => ["#EXTM3U\n#EXTM3U\n"],
            'unquoted relative URI'     => ["#EXTM3U\n#EXT-X-MAP:URI=init.m4s\n"],
            'unterminated quoted URI'   => ["#EXTM3U\n#EXT-X-MAP:URI=\"init.m4ss\n"],
            'slash in an allowed tag'   => ["#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,NAME=\"/library/x\"\n"],
            'backslash in an allowed tag' => ["#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,NAME=\"a\\b\"\n"],
            'unquoted *-URI'            => ["#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,X-URI=https:evil.test\n"],
            'quoted bad *-URI'          => ["#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,X-URI=\"https:evil.test\"\n"],
            'empty attribute list'      => ["#EXTM3U\n#EXT-X-MAP:\n"],
            'no attribute list'         => ["#EXTM3U\n#EXT-X-MAP\n"],
            'attribute without a value' => ["#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,DEFAULT\n"],
            'lower-case attribute name' => ["#EXTM3U\n#EXT-X-MEDIA:type=AUDIO\n"],
            'space in attribute name'   => ["#EXTM3U\n#EXT-X-MEDIA:TYPE =AUDIO\n"],
            'quote inside a value'      => ["#EXTM3U\n#EXT-X-MEDIA:NAME=\"a\"b\",TYPE=AUDIO\n"],
            'DEFINE'                    => ["#EXTM3U\n#EXT-X-DEFINE:NAME=\"h\",VALUE=\"https:evil.test\"\n#EXT-X-MAP:URI=\"x{\$h}.m4s\"\n"],
            'variable in a uri line'    => [$ok . "{\$h}.ts\n"],
            'variable in a comment'     => [$ok . "# {\$h}\n"],
            'U+2028 in an EXTINF title' => ["#EXTM3U\n#EXTINF:2.0,t\u{2028}https:evil.test\n00000.ts\n"],
            'U+2029 in a tag'           => ["#EXTM3U\n#EXT-X-VERSION:3\u{2029}?controller=UserViewController&action=show\n"],
            'U+2028 in a comment'       => [$ok . "# x\u{2028}https:evil.test\n"],
            'U+0085 in a tag'           => ["#EXTM3U\n#EXT-X-VERSION:3\u{85}https:evil.test\n"],
            'raw NEL byte'              => ["#EXTM3U\n#EXT-X-VERSION:3\x85https:evil.test\n"],
            'other non-ascii'           => [$ok . "#EXTINF:2.0,caf\u{e9}\n00001.ts\n"],
            'DEL'                       => ["#EXTM3U\n#EXTINF:2.0,\x7f\n00000.ts\n"],
            'NUL'                       => [$ok . "00001.ts\0\n"],
            'CR-only line ends'         => ["#EXTM3U\r#EXT-X-MAP:URI=https:evil.test\r00000.ts\r"],
            'EXTM3UX header'            => ["#EXTM3UX\n00000.ts\n"],
            'EXTM3U with a suffix'      => ["#EXTM3U x\n00000.ts\n"],
            'whitespace-only line'      => ["#EXTM3U\n   \t\n00000.ts\n"],
            'space-only line'           => [$ok . " \n"],
            'tab-only line'             => [$ok . "\t\n"],
            // hls.js reads "#EXTINF:<duration>" and takes the rest of the line as a URI when no comma follows.
            'EXTINF without a comma'    => ["#EXTM3U\n#EXTINF:2.0 https:evil.test\n00000.ts\n"],
            'EXTINF bad duration'       => ["#EXTM3U\n#EXTINF:x,\n00000.ts\n"],
            // At the end of the body hls.js finds no line end after the title and reads it as a URI.
            'EXTINF last, no line end'  => [$ok . '#EXTINF:2.0,t evil.ts'],
            'VERSION with junk'         => ["#EXTM3U\n#EXT-X-VERSION:3 https:evil.test\n"],
            'TARGETDURATION not a number' => ["#EXTM3U\n#EXT-X-TARGETDURATION:x\n"],
            'MEDIA-SEQUENCE not a number' => ["#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:-1\n"],
            'PLAYLIST-TYPE unknown'     => ["#EXTM3U\n#EXT-X-PLAYLIST-TYPE:LIVE\n"],
            'PLAYLIST-TYPE with junk'   => ["#EXTM3U\n#EXT-X-PLAYLIST-TYPE:VOD https:evil.test\n"],
            'lone quote value'          => ["#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,NAME=\"\n"],
            'ENDLIST with a value'      => ["#EXTM3U\n#EXT-X-ENDLIST:x\n"],
            'DISCONTINUITY with a value' => ["#EXTM3U\n#EXT-X-DISCONTINUITY:https:evil.test\n"],
            'INDEPENDENT-SEGMENTS with a value' => ["#EXTM3U\n#EXT-X-INDEPENDENT-SEGMENTS:x\n"],
            'PROGRAM-DATE-TIME junk'    => ["#EXTM3U\n#EXT-X-PROGRAM-DATE-TIME:https:evil.test\n"],
        ];
    }
}
