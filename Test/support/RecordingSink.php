<?php
// A PlexStreamSink double for model tests: records the status, headers and body, and can refuse open() or stop
// after N writes. Controller tests use the real ResponseSink wired to recorders instead.

namespace Kanboard\Plugin\Matinee\Test;

if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

use Kanboard\Plugin\Matinee\Http\PlexStreamSink;

class RecordingSink implements PlexStreamSink
{
    public ?int $status = null;

    /** @var array<string, string> */
    public array $headers = [];

    public string $body = '';

    public int $opens = 0;

    public int $writes = 0;

    public function __construct(private readonly bool $refuse = false, private readonly ?int $stopAfter = null)
    {
    }

    public function open(int $status, array $headers): bool
    {
        $this->opens++;
        $this->status  = $status;
        $this->headers = $headers;
        return ! $this->refuse;
    }

    public function write(string $chunk): bool
    {
        $this->writes++;
        $this->body .= $chunk;
        return $this->stopAfter === null || $this->writes < $this->stopAfter;
    }
}
