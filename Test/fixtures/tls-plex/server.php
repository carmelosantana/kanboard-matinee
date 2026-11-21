<?php
// A TLS listener for CurlTransportDiscoveryTest: completes the handshake with the given certificate and records how
// many handshakes completed, how many connections it has finished with, and every byte a client sent after a
// handshake. Usage: php server.php PORT CERT KEY RECORD_DIR
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

[, $port, $cert, $key, $dir] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:' . (int) $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    exit(1);
}
file_put_contents($dir . '/handshakes', '0');
file_put_contents($dir . '/bytes', '');
file_put_contents($dir . '/done', '0');

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue; // a readiness probe, or a client that refused the certificate
    }
    file_put_contents($dir . '/handshakes', (string) ((int) file_get_contents($dir . '/handshakes') + 1));
    stream_set_timeout($conn, 1);
    $data = '';
    while (! feof($conn)) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            if (stream_get_meta_data($conn)['timed_out'] || $chunk === false) {
                break;
            }
            continue;
        }
        $data .= $chunk;
        if (str_contains($data, "\r\n\r\n")) {
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
            break;
        }
    }
    file_put_contents($dir . '/bytes', $data, FILE_APPEND);
    fclose($conn);
    file_put_contents($dir . '/done', (string) ((int) file_get_contents($dir . '/done') + 1));
}
