<?php

use Kanboard\Plugin\Matinee\Http\ByteRange;
use PHPUnit\Framework\TestCase;

/** ByteRange (C1b; S11): the client's Range becomes a clamped window, and the upstream Content-Range is checked. */
class ByteRangeTest extends TestCase
{
    private const W = 8388608;

    public function testTheWindowIsEightMebibytes(): void
    {
        $this->assertSame(8388608, ByteRange::WINDOW);
    }

    /** @dataProvider windows */
    public function testWindow(?string $header, array $expected): void
    {
        $this->assertSame($expected, ByteRange::window($header));
    }

    public static function windows(): array
    {
        $w = self::W;
        return [
            'none'                        => [null, [0, $w - 1]],
            'empty'                       => ['', [0, $w - 1]],
            'bytes=0-'                    => ['bytes=0-', [0, $w - 1]],
            'bytes=100-199'               => ['bytes=100-199', [100, 199]],
            'bytes=100-'                  => ['bytes=100-', [100, 100 + $w - 1]],
            'bytes=0-99999999'            => ['bytes=0-99999999', [0, $w - 1]],
            'bytes=100-100'               => ['bytes=100-100', [100, 100]],
            'suffix'                      => ['bytes=-500', [0, $w - 1]],
            'several ranges'              => ['bytes=0-1,5-9', [0, $w - 1]],
            'end before start'            => ['bytes=9-1', [0, $w - 1]],
            'another unit'                => ['items=0-1', [0, $w - 1]],
            '19-digit start'              => ['bytes=1234567890123456789-', [0, $w - 1]],
            '19-digit end'                => ['bytes=0-1234567890123456789', [0, $w - 1]],
            '19-digit end, nonzero start' => ['bytes=100-1234567890123456789', [0, $w - 1]],
            '18-digit start'              => ['bytes=123456789012345678-', [123456789012345678, 123456789012345678 + $w - 1]],
            'spaces'                      => ['bytes= 0-1', [0, $w - 1]],
            'trailing newline'            => ["bytes=100-199\n", [0, $w - 1]],
        ];
    }

    /** @dataProvider upstreams */
    public function testUpstream(?string $header, ?array $expected): void
    {
        $this->assertSame($expected, ByteRange::upstream($header, 100, 199));
    }

    public static function upstreams(): array
    {
        return [
            'exact'               => ['bytes 100-199/1000', [100, 199, 1000]],
            'shorter'             => ['bytes 100-150/1000', [100, 150, 1000]],
            'the start moved'     => ['bytes 101-199/1000', null],
            'an earlier start'    => ['bytes 99-199/1000', null],
            'past the end'        => ['bytes 100-300/1000', null],
            'unknown total'       => ['bytes 100-199/*', null],
            'end at the total'    => ['bytes 100-1000/1000', null],
            'end before start'    => ['bytes 100-99/1000', null],
            '19-digit total'      => ['bytes 100-199/1234567890123456789', null],
            'no header'           => [null, null],
            'trailing junk'       => ['bytes 100-199/1000, x', null],
            'unsatisfied form'    => ['bytes */1000', null],
            'end equals total-1'  => ['bytes 100-199/200', [100, 199, 200]],
            'end equals total'    => ['bytes 100-199/199', null],
        ];
    }

    /** @dataProvider unsatisfied */
    public function testUnsatisfiedTotal(?string $header, ?int $expected): void
    {
        $this->assertSame($expected, ByteRange::unsatisfiedTotal($header));
    }

    public static function unsatisfied(): array
    {
        return [
            'bytes */1000'   => ['bytes */1000', 1000],
            'bytes */x'      => ['bytes */x', null],
            '19-digit total' => ['bytes */1234567890123456789', null],
            '18-digit total' => ['bytes */123456789012345678', 123456789012345678],
            'a range'        => ['bytes 0-1/2', null],
            'no header'      => [null, null],
        ];
    }
}
