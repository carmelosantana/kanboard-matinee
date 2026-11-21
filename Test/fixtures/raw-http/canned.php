<?php
// A raw HTTP server for CurlTransportTest, run as `php canned.php <port>`: it answers each request on 127.0.0.1:<port>
// with the canned bytes for its path, framing `php -S` cannot send (trailers, 1xx, Content-Length beside chunked).
// Unknown paths get a 404. It serves until it is terminated.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

const CANNED = [
    // An empty chunked body whose trailer names headers the response already set (M1).
    '/trailer-empty' => "HTTP/1.1 200 OK\r\n"
        . "Content-Type: audio/mpeg\r\n"
        . "Transfer-Encoding: chunked\r\n"
        . "Trailer: Content-Type, Content-Length\r\n"
        . "Connection: close\r\n\r\n"
        . "0\r\nContent-Type: text/html\r\nContent-Length: 999\r\n\r\n",
    // Content-Length beside Transfer-Encoding: the length is meaningless (RFC 9112 §6.3) (M2).
    '/cl-te' => "HTTP/1.1 200 OK\r\n"
        . "Content-Type: audio/mpeg\r\n"
        . "Content-Length: 2\r\n"
        . "Transfer-Encoding: chunked\r\n"
        . "Connection: close\r\n\r\n"
        . "3\r\nabc\r\n0\r\n\r\n",
    // A 103 whose Content-Range must not survive into the final 200.
    '/early-hints' => "HTTP/1.1 103 Early Hints\r\n"
        . "Content-Range: bytes 0-0/1\r\n\r\n"
        . "HTTP/1.1 200 OK\r\n"
        . "Content-Type: audio/mpeg\r\n"
        . "Content-Length: 3\r\n"
        . "Connection: close\r\n\r\n"
        . "abc",
];

$port   = (int) ($argv[1] ?? 0);
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    exit(1);
}
while (true) {
    $client = @stream_socket_accept($server, 60);
    if ($client === false) {
        continue;
    }
    $path = '';
    while (($line = fgets($client)) !== false && trim($line) !== '') {
        if ($path === '' && preg_match('#^GET (/[^ ?]*)#', $line, $m) === 1) {
            $path = $m[1];
        }
    }
    fwrite($client, CANNED[$path] ?? "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($client);
}
