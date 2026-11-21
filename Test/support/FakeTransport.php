<?php
// A PlexTransport double: records every request and answers from a queue of responses or exceptions.

namespace Kanboard\Plugin\Matinee\Test;

if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

use Kanboard\Plugin\Matinee\Http\PlexResponse;
use Kanboard\Plugin\Matinee\Http\PlexTransport;

class FakeTransport implements PlexTransport
{
    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $requests = [];

    /** @var list<PlexResponse|\Throwable> */
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

    public function get(string $url, #[\SensitiveParameter] array $headers): PlexResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers];
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
