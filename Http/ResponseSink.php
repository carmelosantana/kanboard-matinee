<?php

namespace Kanboard\Plugin\Matinee\Http;

/**
 * The real PlexStreamSink: it opens the browser's response for a proxied stream and writes the body as it
 * arrives (C3e, C3f; spec §11 S9, S11). It is the only thing that opens a stream's response.
 *
 * open() runs, in order: end every output buffer (so nothing queued earlier is sent); remove the queued Pragma,
 * Expires and X-Powered-By headers; send the status; send the forwardable headers under their canonical names
 * (PlexResponse::FORWARDED_HEADERS only, never one with a line break); then send FIXED_HEADERS. It never sends
 * X-Accel-Buffering: nginx's buffering is what frees the FPM worker when a client stops reading (C1c).
 *
 * write() outputs and flushes a chunk, then returns false once the client has gone, so the transport aborts
 * cleanly instead of PHP bailing out mid-write (C3f; the stream endpoints set ignore_user_abort(true)).
 *
 * Every PHP call is injectable, so controller tests wire this class to recorders.
 *
 * @package Kanboard\Plugin\Matinee\Http
 */
final class ResponseSink implements PlexStreamSink
{
    public const FIXED_HEADERS = [
        'X-Content-Type-Options'       => 'nosniff',
        'Content-Security-Policy'      => "default-src 'none'; sandbox",
        'Content-Disposition'          => 'inline',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Referrer-Policy'              => 'same-origin',
        'Cache-Control'                => 'private, no-store',
    ];

    /** The headers PHP or a session may have queued that a stream must not carry. */
    private const REMOVED_HEADERS = ['Pragma', 'Expires', 'X-Powered-By'];

    private readonly \Closure $status;
    private readonly \Closure $header;
    private readonly \Closure $removeHeader;
    private readonly \Closure $output;
    private readonly \Closure $aborted;
    private readonly \Closure $endBuffers;

    private bool $opened = false;

    public function __construct(
        ?\Closure $status = null,
        ?\Closure $header = null,
        ?\Closure $removeHeader = null,
        ?\Closure $output = null,
        ?\Closure $aborted = null,
        ?\Closure $endBuffers = null
    ) {
        $this->status       = $status ?? static function (int $code): void { http_response_code($code); };
        $this->header       = $header ?? static function (string $line): void { header($line); };
        $this->removeHeader = $removeHeader ?? static function (string $name): void { header_remove($name); };
        $this->output       = $output ?? static function (string $chunk): void {
            echo $chunk;
            flush();
        };
        $this->aborted      = $aborted ?? static fn (): bool => connection_aborted() === 1;
        $this->endBuffers   = $endBuffers ?? static function (): void {
            while (ob_get_level() > 0 && ob_end_clean()) {
                // discard whatever an earlier layer buffered
            }
        };
    }

    /** @param array<string, string> $headers forwardable headers with lowercased names (PlexResponse::$headers) */
    public function open(int $status, array $headers): bool
    {
        if ($this->opened) {
            throw new \LogicException('ResponseSink: open() runs once.');
        }
        $this->opened = true;

        ($this->endBuffers)();
        foreach (self::REMOVED_HEADERS as $name) {
            ($this->removeHeader)($name);
        }
        ($this->status)($status);
        foreach ($headers as $name => $value) {
            if (! in_array($name, PlexResponse::FORWARDED_HEADERS, true) || preg_match('/[\r\n\x00]/', (string) $value) === 1) {
                continue;
            }
            ($this->header)(ucwords($name, '-') . ': ' . $value);
        }
        foreach (self::FIXED_HEADERS as $name => $value) {
            ($this->header)($name . ': ' . $value);
        }
        return true;
    }

    public function opened(): bool
    {
        return $this->opened;
    }

    public function write(string $chunk): bool
    {
        if (! $this->opened) {
            throw new \LogicException('ResponseSink: write() before open().');
        }
        ($this->output)($chunk);
        return ! ($this->aborted)();
    }
}
