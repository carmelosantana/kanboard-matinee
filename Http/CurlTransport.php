<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * The real PlexTransport (spec §3, §11 S8, S9, S11): one guarded, pinned GET per call, no redirects, no proxy.
 *
 * - Every get() and stream() runs UrlGuard::check() on the URL's scheme, host and port, so a DNS answer that changes between
 *   requests is judged afresh. UnsafeUrlException propagates.
 * - curl connects only to the address the guard approved: the request URL is rebuilt from the checked host and
 *   port, and for a hostname CURLOPT_RESOLVE pins `host:port` to the checked IP (an IP literal needs no DNS, and
 *   libcurl rejects a RESOLVE entry for an IPv6 literal). After the transfer the connected address
 *   (CURLINFO_PRIMARY_IP) must equal the pinned one, or the answer is dropped as `plex.unreachable`.
 * - If curl rejects any option the request is not sent: a half-configured handle would echo the body to stdout.
 * - Redirects are returned, never followed. Only http and https, no proxy from the environment, TLS verified.
 * - get()'s body is capped at $maxBytes: the write callback aborts the transfer past the cap (`plex.too_large`).
 * - stream() (C3a, C3c, C3f) shares get()'s guard, pin, header rules, protocols, proxy and TLS. Its timeout, low-speed
 *   limit and byte cap come from opts; the cap is counted in the write callback and cut exactly. The first write
 *   checks the connected address through the $primaryIp seam before the sink is opened (after the transfer for an
 *   empty body). No callback lets an exception through libcurl: a sink exception is stored and rethrown after.
 * - Response headers go to PlexResponse, which keeps only the forwardable ones. Header lines that arrive after the
 *   body has started (chunked trailers) are ignored. Callers cannot set hop-by-hop or routing headers.
 * - curl errors become `plex.unreachable` with nothing else: no curl message, URL or query (S9). Nothing here
 *   turns on CURLOPT_VERBOSE or CURLINFO_HEADER_OUT, or logs curl_getinfo().
 * - discoverPlexDirect() is the one place host-name verification is off: a guarded, pinned TLS handshake to an
 *   `https://<IPv4>` base (CURLOPT_CONNECT_ONLY, so no request and no header is sent) that reads the leaf
 *   certificate's names. The chain is still verified. It never carries a token; get() always verifies both.
 * - $caFile replaces the CA bundle (tests trust a throwaway CA with it); production leaves it null.
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

    /** @var \Closure(\CurlHandle): string the connected address; a test seam for the pin check (C3c) */
    private readonly \Closure $primaryIp;

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
        private readonly int $maxBytes = 2097152,
        private readonly ?string $caFile = null,
        ?\Closure $primaryIp = null
    ) {
        if ($connectTimeout < 1 || $timeout < 1 || $maxBytes < 1) {
            throw new \InvalidArgumentException('connectTimeout, timeout and maxBytes must be at least 1.');
        }
        $this->primaryIp = $primaryIp ?? static fn (\CurlHandle $ch): string => (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    }

    /**
     * @param array<string, string> $headers
     * @param array{timeout?: int} $opts
     */
    public function get(string $url, #[\SensitiveParameter] array $headers, array $opts = []): PlexResponse
    {
        $timeout = self::checkGetOpts($opts)['timeout'] ?? $this->timeout;
        [$requestUrl, $pin] = $this->target($url);
        $headerLines = self::headerLines($headers);

        $body        = '';
        $tooLarge    = false;
        $bodyStarted = false;
        $received    = [];
        $ch = null;
        try {
            $ch = curl_init();
            $set = curl_setopt_array($ch, $this->requestOptions($requestUrl, $pin, $headerLines, $timeout, $received, $bodyStarted) + [
                CURLOPT_MAXFILESIZE    => $this->maxBytes,
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
            if (! $this->pinned($ch, $pin['ip'])) {
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
     * @param array<string, string> $headers
     * @param array{timeout: int, lowSpeedLimit: int, lowSpeedTime: int, maxBytes: int} $opts
     */
    public function stream(string $url, #[\SensitiveParameter] array $headers, array $opts, PlexStreamSink $sink): PlexResponse
    {
        $opts = self::checkStreamOpts($opts);
        [$requestUrl, $pin] = $this->target($url);
        $headerLines = self::headerLines($headers);

        $bodyStarted = false;
        $received    = [];
        $opened      = false; // open() has been attempted (the pin was checked first)
        $refused     = false; // the pin failed, open() refused or write() said stop: write nothing more
        $pinFailed   = false;
        $tooLarge    = false;
        $written     = 0;
        $sinkError   = null;  // a \Throwable from the sink, rethrown after curl_exec (C3f)
        $ch = null;

        // The pin check, then open(): runs once, before the first body byte or after an empty transfer (C3c).
        $begin = function (\CurlHandle $ch) use ($sink, $pin, &$received, &$opened, &$refused, &$pinFailed): bool {
            $opened = true;
            if (! $this->pinned($ch, $pin['ip'])) {
                $pinFailed = $refused = true;
                return false;
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($sink->open($status, (new PlexResponse($status, '', $received))->headers) !== true) {
                $refused = true;
                return false;
            }
            return true;
        };

        try {
            $ch = curl_init();
            $set = curl_setopt_array($ch, $this->requestOptions($requestUrl, $pin, $headerLines, $opts['timeout'], $received, $bodyStarted) + [
                CURLOPT_LOW_SPEED_LIMIT => $opts['lowSpeedLimit'],
                CURLOPT_LOW_SPEED_TIME  => $opts['lowSpeedTime'],
                CURLOPT_WRITEFUNCTION   => static function (\CurlHandle $ch, string $chunk) use ($sink, $begin, $opts, &$bodyStarted, &$opened, &$refused, &$tooLarge, &$written, &$sinkError): int {
                    try {
                        $bodyStarted = true;
                        if ($refused || (! $opened && ! $begin($ch))) {
                            return 0;
                        }
                        $room = $opts['maxBytes'] - $written;
                        if (strlen($chunk) > $room) {
                            // The exact cut (C3a): write what fits, then abort as too large.
                            $tooLarge = $refused = true;
                            if ($room > 0) {
                                $written += $room;
                                $sink->write(substr($chunk, 0, $room));
                            }
                            return 0;
                        }
                        $written += strlen($chunk);
                        if ($sink->write($chunk) !== true) {
                            $refused = true;
                            return 0;
                        }
                        return strlen($chunk);
                    } catch (\Throwable $e) {
                        // Never let an exception unwind through libcurl.
                        $sinkError ??= $e;
                        $refused = true;
                        return 0;
                    }
                },
            ]);
            if ($set !== true) {
                throw PlexException::unreachable();
            }

            $ok     = curl_exec($ch) !== false;
            $errno  = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($sinkError === null && ! $refused && $ok && $errno === 0 && ! $opened) {
                // An empty body: the pin check and open() still run, in that order, before returning.
                try {
                    $begin($ch);
                } catch (\Throwable $e) {
                    $sinkError = $e;
                }
            }
        } catch (PlexException $e) {
            throw $e;
        } catch (\Throwable) {
            throw PlexException::unreachable();
        } finally {
            unset($ch);
        }

        if ($sinkError !== null) {
            throw $sinkError;
        }
        if ($pinFailed) {
            throw PlexException::unreachable();
        }
        if ($tooLarge) {
            throw PlexException::tooLarge();
        }
        if ($refused) {
            return new PlexResponse($status, '', $received);
        }
        if (! $ok || $errno !== 0) {
            throw PlexException::unreachable();
        }
        return new PlexResponse($status, '', $received);
    }

    /**
     * stream()'s opts: exactly timeout, lowSpeedLimit, lowSpeedTime and maxBytes, each an int of at least 1.
     *
     * @return array{timeout: int, lowSpeedLimit: int, lowSpeedTime: int, maxBytes: int}
     */
    public static function checkStreamOpts(array $opts): array
    {
        $keys = ['timeout', 'lowSpeedLimit', 'lowSpeedTime', 'maxBytes'];
        $given = array_keys($opts);
        sort($given);
        $wanted = $keys;
        sort($wanted);
        if ($given !== $wanted) {
            throw new \InvalidArgumentException('Stream opts must be exactly timeout, lowSpeedLimit, lowSpeedTime and maxBytes.');
        }
        foreach ($opts as $value) {
            if (! is_int($value) || $value < 1) {
                throw new \InvalidArgumentException('Stream opts must be whole numbers of at least 1.');
            }
        }
        return $opts;
    }

    /** @return array{timeout?: int} get()'s opts: nothing, or a timeout of at least 1 */
    private static function checkGetOpts(array $opts): array
    {
        if (array_diff(array_keys($opts), ['timeout']) !== []
            || (array_key_exists('timeout', $opts) && (! is_int($opts['timeout']) || $opts['timeout'] < 1))) {
            throw new \InvalidArgumentException('get() opts may carry only a timeout of at least 1.');
        }
        return $opts;
    }

    /**
     * The options get() and stream() share: protocols, trust, the pin, no redirects or proxy, timeouts, the
     * request headers and the response-header callback (which ignores trailers).
     *
     * @param array{host:string, port:int, ip:string} $pin
     * @param list<string> $headerLines
     * @param array<string, string> $received
     * @return array<int, mixed>
     */
    private function requestOptions(string $requestUrl, array $pin, #[\SensitiveParameter] array $headerLines, int $timeout, array &$received, bool &$bodyStarted): array
    {
        $protocols = defined('CURLOPT_PROTOCOLS_STR')
            ? [CURLOPT_PROTOCOLS_STR => 'http,https', CURLOPT_REDIR_PROTOCOLS_STR => 'http,https']
            : [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
        return $protocols + $this->trustOptions() + [
            CURLOPT_URL            => $requestUrl,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RESOLVE        => self::resolveList($pin['host'], $pin['port'], $pin['ip']),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY          => '',
            CURLOPT_NOPROXY        => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received, &$bodyStarted): int {
                try {
                    if ($bodyStarted) {
                        // a chunked trailer: never let it overwrite the response headers
                    } elseif (preg_match('#^HTTP/\S+\s+\d{3}#', $line) === 1) {
                        $received = []; // a new response (after 100 Continue): start over
                    } elseif (preg_match('/^([^:\s]+):[ \t]*(.*?)\s*$/', $line, $m) === 1) {
                        $received[$m[1]] = $m[2];
                    }
                    return strlen($line);
                } catch (\Throwable) {
                    return 0;
                }
            },
        ];
    }

    /** True when the handle's connected address is the pinned one (read through the $primaryIp seam). */
    private function pinned(\CurlHandle $ch, string $ip): bool
    {
        return self::sameAddress((string) ($this->primaryIp)($ch), $ip);
    }

    public function discoverPlexDirect(string $base): ?string
    {
        if (PlexDirect::ipv4Endpoint($base) === null) {
            return null;
        }
        $pin = $this->guard->check($base); // UnsafeUrlException propagates: nothing is sent
        $ch = null;
        try {
            $ch = curl_init();
            $protocols = defined('CURLOPT_PROTOCOLS_STR')
                ? [CURLOPT_PROTOCOLS_STR => 'https', CURLOPT_REDIR_PROTOCOLS_STR => 'https']
                : [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS];
            $set = curl_setopt_array($ch, $protocols + $this->trustOptions() + [
                CURLOPT_URL            => 'https://' . $pin['ip'] . ':' . $pin['port'] . '/',
                CURLOPT_CONNECT_ONLY   => true, // the handshake and nothing else: no request line, no header
                CURLOPT_CERTINFO       => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY          => '',
                CURLOPT_NOPROXY        => '*',
                CURLOPT_SSL_VERIFYPEER => true, // the chain must be trusted
                CURLOPT_SSL_VERIFYHOST => 0,    // only here: the certificate cannot name an IP, and no token is sent
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT        => $this->timeout,
            ]);
            if ($set !== true) {
                throw PlexException::unreachable();
            }
            if (curl_exec($ch) === false || curl_errno($ch) !== 0 || ! $this->pinned($ch, $pin['ip'])) {
                throw PlexException::unreachable();
            }
            $certificates = curl_getinfo($ch, CURLINFO_CERTINFO);
            $leaf = is_array($certificates) && is_array($certificates[0] ?? null) ? $certificates[0] : [];
            return PlexDirect::fromCertificate($base, PlexDirect::certificateNames($leaf));
        } catch (PlexException $e) {
            throw $e;
        } catch (\Throwable) {
            throw PlexException::unreachable();
        } finally {
            unset($ch);
        }
    }

    /** @return array<int, string> CURLOPT_CAINFO when a CA file was given, else nothing (the system bundle) */
    private function trustOptions(): array
    {
        return $this->caFile !== null ? [CURLOPT_CAINFO => $this->caFile] : [];
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
