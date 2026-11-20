<?php
// A fake Plex server for CurlTransportTest, run as `php -S 127.0.0.1:<port> index.php`.
// Every request bumps the hit counter in the file named by FAKE_PLEX_HITS. The X-Plex-Token header is never
// logged or echoed: /identity reports only whether it arrived and its SHA-256.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

$hits = getenv('FAKE_PLEX_HITS');
if (is_string($hits) && $hits !== '') {
    $fh = fopen($hits, 'c+');
    flock($fh, LOCK_EX);
    $n = (int) stream_get_contents($fh);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) ($n + 1));
    flock($fh, LOCK_UN);
    fclose($fh);
}

$path  = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$token = $_SERVER['HTTP_X_PLEX_TOKEN'] ?? null;

switch ($path) {
    case '/identity':
        header('Content-Type: application/json');
        header('Set-Cookie: session=fake');
        echo json_encode(['MediaContainer' => [
            'machineIdentifier' => 'fake-machine',
            'version'           => '1.0.0',
            'host'              => $_SERVER['HTTP_HOST'] ?? '',
            'query'             => $_SERVER['QUERY_STRING'] ?? '',
            'tokenSeen'         => $token !== null,
            'tokenSha256'       => $token !== null ? hash('sha256', $token) : null,
        ]]);
        return true;
    case '/library/sections':
        header('Content-Type: application/json');
        echo json_encode(['MediaContainer' => ['Directory' => [['key' => '1', 'title' => 'Movies', 'type' => 'movie']]]]);
        return true;
    case '/redirect':
        header('Location: /identity', true, 302);
        echo 'redirected';
        return true;
    case '/big':
        // 3 MiB with a Content-Length.
        $body = str_repeat('x', 3 * 1024 * 1024);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($body));
        echo $body;
        return true;
    case '/big-chunked':
        // 3 MiB with no Content-Length, flushed in pieces, so only a running byte count can stop it.
        header('Content-Type: application/octet-stream');
        $piece = str_repeat('y', 64 * 1024);
        for ($i = 0; $i < 48; $i++) {
            echo $piece;
            flush();
        }
        return true;
}
http_response_code(404);
echo 'not found';
return true;
