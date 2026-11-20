<?php
// A raw HTTP server for CurlTransportTest, run as `php chunked-trailer.php <port>`: it answers every request on
// 127.0.0.1:<port> with a chunked JSON body followed by a `Content-Type: text/evil` trailer, which `php -S`
// cannot send. It serves until it is terminated.

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
    while (($line = fgets($client)) !== false && trim($line) !== '') {
        // read and drop the request head
    }
    $body = '{"ok":true}';
    fwrite($client, "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/json\r\n"
        . "Transfer-Encoding: chunked\r\n"
        . "Trailer: Content-Type\r\n"
        . "Connection: close\r\n\r\n"
        . dechex(strlen($body)) . "\r\n" . $body . "\r\n"
        . "0\r\nContent-Type: text/evil\r\n\r\n");
    fclose($client);
}
