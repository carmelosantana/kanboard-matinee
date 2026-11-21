<?php
// A live ResponseSink check for ResponseSinkTest, run as `php -S 127.0.0.1:<port> stream.php`. It queues Pragma
// and Expires through PHP's session cache limiter, leaves junk in an output buffer, then opens a ResponseSink with
// a forwardable set plus a Set-Cookie, and writes three chunks one second apart.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }

require_once __DIR__ . '/../../../Http/PlexResponse.php';
require_once __DIR__ . '/../../../Http/PlexStreamSink.php';
require_once __DIR__ . '/../../../Http/ResponseSink.php';

use Kanboard\Plugin\Matinee\Http\ResponseSink;

ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_cache_limiter('nocache');
session_start();
session_abort();

ob_start();
echo 'JUNK-BEFORE-OPEN';

$sink = new ResponseSink();
$sink->open(206, ['content-type' => 'audio/mpeg', 'content-range' => 'bytes 0-2/3', 'set-cookie' => 'x=1']);
foreach (['a', 'b', 'c'] as $i => $chunk) {
    if ($i > 0) {
        sleep(1);
    }
    $sink->write($chunk);
}
return true;
