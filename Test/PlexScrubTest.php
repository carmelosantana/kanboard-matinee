<?php

use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexScrub;
use PHPUnit\Framework\TestCase;

/**
 * PlexScrub::stripToken removes every X-Plex-Token query key, in any case, spelling and percent-encoding, from a
 * Plex-returned URI and leaves everything else byte-for-byte; when a token-like string survives anywhere it refuses
 * the URI with `plex.token_in_uri` (spec §3).
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
            'client identifier is kept'   => ['/a?X-Plex-Client-Identifier=c&X-Plex-Product=p', '/a?X-Plex-Client-Identifier=c&X-Plex-Product=p'],
            'digits in the key go too'    => ['/a?X-Plex-Token2=secret&b=2', '/a?b=2'],
            'semicolon separator'         => ['/a?b=1;X-Plex-Token=secret', '/a?b=1'],
            'separators are kept as sent' => ['/a?a=1;b=2&X-Plex-Token=secret&c=3', '/a?a=1;b=2&c=3'],
            'plus before the equals'      => ['/a?X-Plex-Token+=secret', '/a'],
            'encoded space after'         => ['/a?X-Plex-Token%20=secret', '/a'],
            'encoded space before'        => ['/a?%20X-Plex-Token=secret', '/a'],
            'encoded NUL'                 => ['/a?X-Plex-Token%00=secret', '/a'],
            'double-encoded dashes'       => ['/a?X%252DPlex%252DToken=secret', '/a'],
            'triple-encoded dashes'       => ['/a?X%25252DPlex%25252DToken=secret&b=2', '/a?b=2'],
            'underscores'                 => ['/a?X_Plex_Token=secret', '/a'],
            'dots and spaces'             => ['/a?x.plex token=secret', '/a'],
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

    /** @dataProvider tokenless */
    public function testAUriWithoutATokenComesBackByteIdentical(string $in): void
    {
        $this->assertSame($in, PlexScrub::stripToken($in));
    }

    public function tokenless(): array
    {
        return [
            'plain path'       => ['/library/parts/1/file.mp4'],
            'odd separators'   => ['/a?&&b=1;;c=%2F&#x;y'],
            'encoded bytes'    => ['/a?q=a%20b+c%00%ff&z=%252F'],
            'absolute'         => ['https://h.example.test:32400/x?a=1#frag'],
        ];
    }

    /**
     * Whatever is left after stripping is decoded (up to three rounds) and reduced to letters; if `xplextoken`
     * still shows up anywhere, a token may be riding along, so the URI is refused rather than returned.
     *
     * @dataProvider refused
     */
    public function testATokenThatSurvivesStrippingRefusesTheWholeUri(string $in): void
    {
        try {
            $out = PlexScrub::stripToken($in);
            $this->fail('expected refusal, got ' . $out);
        } catch (PlexException $e) {
            $this->assertSame(PlexException::TOKEN_IN_URI, $e->reason);
            $this->assertSame(PlexException::TOKEN_IN_URI, $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function refused(): array
    {
        return [
            'nested in a value'        => ['/a?url=%2Flibrary%2Fx%3FX-Plex-Token%3Dsecret'],
            'nested, double-encoded'   => ['/a?url=%252Fx%253FX-Plex-Token%253Dsecret'],
            'nested, plain'            => ['/a?next=/x?X-Plex-Token=secret'],
            'a longer key'             => ['/a?my-X-Plex-Token=secret'],
            'in the path'              => ['/X-Plex-Token/secret'],
            'as a value'               => ['/a?name=X-Plex-Token'],
            'encoded equals in key'    => ['/a?X-Plex-Token%3Dsecret'],
            'in the fragment value'    => ['/a#u=X-Plex-Token%3Dsecret'],
            'four rounds of encoding'  => ['/a?X%2525252DPlex%2525252DToken=secret'],
        ];
    }
}
