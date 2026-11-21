<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * Byte ranges for direct parts (C1b; spec §11 S11). Every number has at most 18 digits, so it always fits an int
 * and a window end cannot overflow.
 *
 * - window(): the client's `Range` becomes one clamped window of at most WINDOW bytes. Anything but a single
 *   `bytes=a-b` or `bytes=a-` (a suffix range, several ranges, another unit, an end before the start, a number
 *   over 18 digits) is ignored, as RFC 9110 allows, and gives the first window.
 * - upstream(): Plex's `Content-Range` on a 206 must start at the requested byte and end within the window.
 * - unsatisfiedTotal(): the total N of a 416's unsatisfied-range form, `bytes *` then a slash and N.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class ByteRange
{
    public const WINDOW = 8388608;

    /** @return array{0: int, 1: int} [start, endInclusive] */
    public static function window(?string $header): array
    {
        if ($header === null || preg_match('/\Abytes=(\d{1,18})-(\d{0,18})\z/', $header, $m) !== 1) {
            return [0, self::WINDOW - 1];
        }
        $start = (int) $m[1];
        $last  = $start + self::WINDOW - 1;
        if ($m[2] === '') {
            return [$start, $last];
        }
        $end = (int) $m[2];
        if ($end < $start) {
            return [0, self::WINDOW - 1];
        }
        return [$start, min($end, $last)];
    }

    /** @return array{0: int, 1: int, 2: int}|null [s, e, total] when `bytes s-e/total` fits the requested window */
    public static function upstream(?string $contentRange, int $start, int $end): ?array
    {
        if ($contentRange === null || preg_match('/\Abytes (\d{1,18})-(\d{1,18})\/(\d{1,18})\z/', $contentRange, $m) !== 1) {
            return null;
        }
        [$s, $e, $total] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($s !== $start || $e < $start || $e > $end || $e >= $total) {
            return null;
        }
        return [$s, $e, $total];
    }

    /** The total N of an unsatisfied-range Content-Range (`bytes *`, a slash, N), or null. */
    public static function unsatisfiedTotal(?string $contentRange): ?int
    {
        if ($contentRange === null || preg_match('/\Abytes \*\/(\d{1,18})\z/', $contentRange, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }
}
