<?php

use Kanboard\Plugin\Matinee\Http\PlexScrub;
use PHPUnit\Framework\TestCase;

/**
 * PlexScrub::stripToken removes every X-Plex-Token query key, in any case and in percent-encoded form, from a
 * Plex-returned URI and leaves everything else byte-for-byte (spec §3).
 */
class PlexScrubTest extends TestCase
{
    /** @dataProvider uris */
    public function testStripToken(string $in, string $expected): void
    {
        $this->assertSame($expected, PlexScrub::stripToken($in));
    }

    public function uris(): array
    {
        return [
            'canonical case, only param'  => ['/a/b.m3u8?X-Plex-Token=secret', '/a/b.m3u8'],
            'lowercase'                   => ['/a?x-plex-token=secret', '/a'],
            'uppercase'                   => ['/a?X-PLEX-TOKEN=secret', '/a'],
            'mixed case'                  => ['/a?x-PlEx-ToKeN=secret', '/a'],
            'first of several'            => ['/a?X-Plex-Token=secret&b=2&c=3', '/a?b=2&c=3'],
            'mid-query'                   => ['/a?b=2&x-plex-token=secret&c=3', '/a?b=2&c=3'],
            'last'                        => ['/a?b=2&X-Plex-Token=secret', '/a?b=2'],
            'repeated keys'               => ['/a?X-Plex-Token=one&b=2&x-plex-token=two&X-PLEX-TOKEN=three', '/a?b=2'],
            'percent-encoded key'         => ['/a?X%2DPlex%2DToken=secret&b=2', '/a?b=2'],
            'lowercase percent-encoding'  => ['/a?x%2dplex%2dtoken=secret', '/a'],
            'php array form'              => ['/a?X-Plex-Token[]=secret&b=2', '/a?b=2'],
            'no value'                    => ['/a?X-Plex-Token&b=2', '/a?b=2'],
            'empty value'                 => ['/a?X-Plex-Token=&b=2', '/a?b=2'],
            'absolute URL keeps the rest' => ['https://h.example.test:32400/x?a=1&X-Plex-Token=s#frag', 'https://h.example.test:32400/x?a=1#frag'],
            'fragment, token only'        => ['/a?X-Plex-Token=s#frag', '/a#frag'],
            'token inside the fragment'   => ['/a?b=2#X-Plex-Token=s&k=v', '/a?b=2#k=v'],
            'no query'                    => ['/library/sections', '/library/sections'],
            'empty query'                 => ['/a?', '/a?'],
            'other values preserved raw'  => ['/a?q=a%20b+c&X-Plex-Token=s&z=%2F', '/a?q=a%20b+c&z=%2F'],
            'look-alike keys are kept'    => ['/a?X-Plex-Token2=k&my-X-Plex-Token=k&X-Plex-Client-Identifier=c', '/a?X-Plex-Token2=k&my-X-Plex-Token=k&X-Plex-Client-Identifier=c'],
            'token as a value is kept'    => ['/a?name=X-Plex-Token', '/a?name=X-Plex-Token'],
            'empty pairs dropped quietly' => ['/a?&X-Plex-Token=s&&b=2', '/a?b=2'],
            'empty string'                => ['', ''],
        ];
    }

    public function testNoTokenSurvivesAnyFixture(): void
    {
        foreach ($this->uris() as $name => [$in]) {
            $this->assertStringNotContainsString('secret', PlexScrub::stripToken($in), $name);
        }
    }
}
