<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * The real PlexTransport (spec §3, §11 S8, S9, S11): one guarded, pinned GET per call, no redirects, no proxy.
 *
 * - Every get() runs UrlGuard::check() on the URL's scheme, host and port, so a DNS answer that changes between
 *   requests is judged afresh. UnsafeUrlException propagates.
 * - curl connects only to the address the guard approved: the request URL is rebuilt from the checked host and
 *   port, and for a hostname CURLOPT_RESOLVE pins `host:port` to the checked IP (an IP literal needs no DNS, and
 *   libcurl rejects a RESOLVE entry for an IPv6 literal). After the transfer the connected address
 *   (CURLINFO_PRIMARY_IP) must equal the pinned one, or the answer is dropped as `plex.unreachable`.
 * - If curl rejects any option the request is not sent: a half-configured handle would echo the body to stdout.
 * - Redirects are returned, never followed. Only http and https, no proxy from the environment, TLS verified.
 * - The body is capped at $maxBytes: the write callback aborts the transfer past the cap (`plex.too_large`).
 * - Response headers go to PlexResponse, which keeps only the forwardable ones. Header lines that arrive after the
 *   body has started (chunked trailers) are ignored. Callers cannot set hop-by-hop or routing headers.
 * - curl errors become `plex.unreachable` with nothing else: no curl message, URL or query (S9). Nothing here
 *   turns on CURLOPT_VERBOSE or CURLINFO_HEADER_OUT, or logs curl_getinfo().
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class CurlTransport implements PlexTransport
{
    /** `scheme://authority` then an optional path and query; the authority is judged by the guard. */
    private const URL_SHAPE = '#^(https?)://([^/?]*)(.*)$#i';

    /** Request headers curl or the connection owns; a caller setting them could re-route or desync the request. */
    private const DENIED_HEADERS = ['host', 'transfer-encoding', 'connection', 'content-length', 'upgrade', 'te',
        'trailer', 'keep-alive'];

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
        private readonly int $maxBytes = 2097152
    ) {
        if ($connectTimeout < 1 || $timeout < 1 || $maxBytes < 1) {
            throw new \InvalidArgumentException('connectTimeout, timeout and maxBytes must be at least 1.');
        }
    }

    /** @param array<string, string> $headers */
    public function get(string $url, #[\SensitiveParameter] array $headers): PlexResponse
    {
        [$requestUrl, $pin] = $this->target($url);
        $headerLines = self::headerLines($headers);

        $body        = '';
        $tooLarge    = false;
        $bodyStarted = false;
        $received    = [];
        $ch = null;
        try {
            $ch = curl_init();
            $protocols = defined('CURLOPT_PROTOCOLS_STR')
                ? [CURLOPT_PROTOCOLS_STR => 'http,https', CURLOPT_REDIR_PROTOCOLS_STR => 'http,https']
                : [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
            $set = curl_setopt_array($ch, $protocols + [
                CURLOPT_URL            => $requestUrl,
                CURLOPT_HTTPGET        => true,
                CURLOPT_RESOLVE        => self::resolveList($pin['host'], $pin['port'], $pin['ip']),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY          => '',
                CURLOPT_NOPROXY        => '*',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_MAXFILESIZE    => $this->maxBytes,
                CURLOPT_HTTPHEADER     => $headerLines,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received, &$bodyStarted): int {
                    if ($bodyStarted) {
                        // a chunked trailer: never let it overwrite the response headers
                    } elseif (preg_match('#^HTTP/\S+\s+\d{3}#', $line) === 1) {
                        $received = []; // a new response (after 100 Continue): start over
                    } elseif (preg_match('/^([^:\s]+):[ \t]*(.*?)\s*$/', $line, $m) === 1) {
                        $received[$m[1]] = $m[2];
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body, &$tooLarge, &$bodyStarted): int {
                    $bodyStarted = true;
                    if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                        $tooLarge = true;
                        return 0; // aborts the transfer
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($set !== true) {
                throw PlexException::unreachable();
            }

            $ok    = curl_exec($ch) !== false;
            $errno = curl_errno($ch);
            if ($tooLarge || $errno === CURLE_FILESIZE_EXCEEDED) {
                throw PlexException::tooLarge();
            }
            if (! $ok || $errno !== 0) {
                throw PlexException::unreachable();
            }
            if (! self::sameAddress((string) curl_getinfo($ch, CURLINFO_PRIMARY_IP), $pin['ip'])) {
                throw PlexException::unreachable();
            }
            return new PlexResponse((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body, $received);
        } catch (PlexException $e) {
            throw $e;
        } catch (\Throwable) {
            // A ValueError / TypeError from curl could carry option values in its message: report only the reason.
            throw PlexException::unreachable();
        } finally {
            // curl_close() is a no-op since PHP 8.0 (deprecated in 8.5); dropping the last reference frees it.
            unset($ch);
        }
    }

    /**
     * The CURLOPT_RESOLVE list: one pin for a hostname, none for an IP literal (curl does no DNS for it).
     *
     * @return list<string>
     */
    public static function resolveList(string $host, int $port, string $ip): array
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false ? [] : [self::resolveEntry($host, $port, $ip)];
    }

    /** The CURLOPT_RESOLVE entry pinning hostname:port to ip; an IPv6 ip is bracketed. */
    public static function resolveEntry(string $host, int $port, string $ip): string
    {
        return $host . ':' . $port . ':' . self::bracket($ip);
    }

    /** True when both are IP addresses with the same bytes, however they are spelled. */
    public static function sameAddress(string $a, string $b): bool
    {
        $binA = @inet_pton($a);
        $binB = @inet_pton($b);
        return $binA !== false && $binB !== false && $binA === $binB;
    }

    /**
     * Guard the URL's origin and rebuild the request URL from what the guard checked.
     *
     * @return array{0: string, 1: array{host:string, port:int, ip:string}}
     */
    private function target(string $url): array
    {
        if (preg_match('/[\s\x00-\x1f\x7f\\\\#]/', $url) === 1 || preg_match(self::URL_SHAPE, $url, $m) !== 1) {
            throw new UnsafeUrlException(UnsafeUrlException::INVALID_URL);
        }
        $rest = $m[3];
        if ($rest !== '' && $rest[0] !== '/' && $rest[0] !== '?') {
            throw new UnsafeUrlException(UnsafeUrlException::INVALID_URL);
        }
        // The guard validates the authority strictly (no userinfo, a canonical port) and returns the pin.
        $pin = $this->guard->check(strtolower($m[1]) . '://' . $m[2]);
        if ($rest === '' || $rest[0] === '?') {
            $rest = '/' . $rest;
        }
        $requestUrl = strtolower($m[1]) . '://' . self::bracket($pin['host']) . ':' . $pin['port'] . $rest;
        return [$requestUrl, $pin];
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function headerLines(#[\SensitiveParameter] array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $name  = (string) $name;
            $value = (string) $value;
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new \InvalidArgumentException('A request header has an invalid name or a line break.');
            }
            $lower = strtolower($name);
            if (in_array($lower, self::DENIED_HEADERS, true) || str_starts_with($lower, 'proxy-')) {
                throw new \InvalidArgumentException('A request header names a hop-by-hop or routing header.');
            }
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }

    private static function bracket(string $hostOrIp): string
    {
        return filter_var($hostOrIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $hostOrIp . ']' : $hostOrIp;
    }
}
