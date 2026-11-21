<?php
// A PlexTransport double: records every request and answers from a queue of responses, scripted streams or exceptions.

namespace Kanboard\Plugin\Matinee\Test;

if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

use Kanboard\Plugin\Matinee\Http\CurlTransport;
use Kanboard\Plugin\Matinee\Http\PlexException;
use Kanboard\Plugin\Matinee\Http\PlexResponse;
use Kanboard\Plugin\Matinee\Http\PlexStreamSink;
use Kanboard\Plugin\Matinee\Http\PlexTransport;

class FakeTransport implements PlexTransport
{
    /** @var list<array{url: string, headers: array<string, string>, opts: array<string, int>}> */
    public array $requests = [];

    /** @var list<PlexResponse|\Throwable|array{status: int, headers: array<string, string>, chunks: list<string>}> */
    private array $queue = [];

    /** @var list<string> every base discoverPlexDirect() was asked about */
    public array $discoveries = [];

    /** @var list<string|\Throwable|null> */
    private array $discoverQueue = [];

    /** Scripts the next discoverPlexDirect() answer: a URL, null (no plex.direct name) or an exception. */
    public function discovers(string|\Throwable|null $answer): self
    {
        $this->discoverQueue[] = $answer;
        return $this;
    }

    public function discoverPlexDirect(string $base): ?string
    {
        $this->discoveries[] = $base;
        if ($this->discoverQueue === []) {
            throw new \LogicException('FakeTransport: no queued discovery');
        }
        $next = array_shift($this->discoverQueue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    public function respond(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new PlexResponse($status, $body, $headers);
        return $this;
    }

    public function respondJson(array $data, int $status = 200): self
    {
        return $this->respond($status, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function fail(\Throwable $e): self
    {
        $this->queue[] = $e;
        return $this;
    }

    /** Queues a scripted stream: stream() plays $chunks through the sink after open($status, forwardable headers). */
    public function streams(int $status, array $headers, array $chunks): self
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'chunks' => array_values($chunks)];
        return $this;
    }

    public function get(string $url, #[\SensitiveParameter] array $headers, array $opts = []): PlexResponse
    {
        $opts = CurlTransport::checkGetOpts($opts);
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'opts' => $opts];
        $next = $this->next();
        if (! $next instanceof PlexResponse) {
            throw new \LogicException('FakeTransport: get() found a queued stream');
        }
        return $next;
    }

    /** Plays the next scripted stream as CurlTransport would: open once, honour refusals, cut exactly at maxBytes. */
    public function stream(string $url, #[\SensitiveParameter] array $headers, array $opts, PlexStreamSink $sink): PlexResponse
    {
        $opts = CurlTransport::checkStreamOpts($opts);
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'opts' => $opts];
        $next = $this->next();
        if (! is_array($next)) {
            throw new \LogicException('FakeTransport: stream() found a queued get() response');
        }
        $response = new PlexResponse($next['status'], '', $next['headers']);
        if (! $sink->open($response->status, $response->headers)) {
            return $response;
        }
        $written = 0;
        foreach ($next['chunks'] as $chunk) {
            $room = $opts['maxBytes'] - $written;
            if (strlen($chunk) > $room) {
                if ($room > 0) {
                    $sink->write(substr($chunk, 0, $room));
                }
                throw PlexException::tooLarge();
            }
            $written += strlen($chunk);
            if (! $sink->write($chunk)) {
                break;
            }
        }
        return $response;
    }

    /** @return PlexResponse|array{status: int, headers: array<string, string>, chunks: list<string>} */
    private function next(): PlexResponse|array
    {
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeTransport: no queued response');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
