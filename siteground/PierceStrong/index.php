<?php
/* openGym pass-through for a SiteGround website.
 *
 * Put this folder (index.php, .htaccess, config.php) on your website, for example at
 * public_html/PierceStrong/. Every request under that folder is forwarded to the openGym
 * Node.js project, and the answer is passed back unchanged. To your browser the whole app lives
 * at https://your-site/PierceStrong/, which is what passkey sign-in needs: the app and its API on
 * the same address as the page.
 *
 * Settings are in config.php (copy config.sample.php). Nothing else to change here.
 */

$config = @include __DIR__ . '/config.php';
if (!is_array($config) || empty($config['node_url']) || empty($config['secret'])
    || strpos($config['node_url'], 'REPLACE') !== false || strpos($config['secret'], 'REPLACE') !== false) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "openGym is not set up yet: create config.php next to index.php (see config.sample.php).";
    exit;
}
$node = rtrim($config['node_url'], '/');
$secret = $config['secret'];

// Send bytes straight through: no PHP compression or buffering in the way.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) { ob_end_clean(); }
@set_time_limit(0);
header_remove('X-Powered-By');

/* ---------- work out the path below this folder ---------- */
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');   // e.g. /PierceStrong
$uri = $_SERVER['REQUEST_URI'];
if ($base !== '' && strncasecmp($uri, $base, strlen($base)) === 0) {
    $rest = substr($uri, strlen($base));
} else {
    $rest = $uri;
}
if ($rest === '' || $rest[0] === '?') {
    // /PierceStrong without the slash: the app's files are relative, so it needs the slash.
    header('Location: ' . $base . '/' . $rest, true, 301);
    exit;
}
if ($rest[0] !== '/') { $rest = '/' . $rest; }

/* ---------- request headers to forward ---------- */
$incoming = function_exists('getallheaders') ? getallheaders() : [];
if (!$incoming) {
    foreach ($_SERVER as $k => $v) {
        if (strncmp($k, 'HTTP_', 5) === 0) {
            $incoming[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = $v;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) { $incoming['Content-Type'] = $_SERVER['CONTENT_TYPE']; }
}
$skip = ['host', 'connection', 'keep-alive', 'content-length', 'transfer-encoding', 'te', 'trailer',
         'upgrade', 'expect', 'proxy-authorization', 'proxy-connection', 'x-opengym-proxy',
         'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'cf-connecting-ip'];
$headers = [];
foreach ($incoming as $name => $value) {
    if (in_array(strtolower($name), $skip, true)) { continue; }
    $headers[] = $name . ': ' . $value;
}
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$headers[] = 'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '');
$headers[] = 'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? '');
$headers[] = 'X-Forwarded-Proto: ' . ($https ? 'https' : 'http');
$headers[] = 'X-OpenGym-Proxy: ' . $secret;
$headers[] = 'Expect:';   // no "100 Continue" round trip

/* ---------- forward ---------- */
$method = $_SERVER['REQUEST_METHOD'];
$ch = curl_init($node . $rest);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 1800,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
]);
if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
} elseif ($method !== 'GET' && $method !== 'OPTIONS') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, (string)file_get_contents('php://input'));
}

$started = false;
$hop = ['connection', 'keep-alive', 'transfer-encoding', 'te', 'trailer', 'upgrade', 'proxy-connection'];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$started, $hop, $base, $node) {
    $len = strlen($line);
    $line = rtrim($line, "\r\n");
    if ($line === '') { return $len; }
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
        if ((int)$m[1] >= 200) { http_response_code((int)$m[1]); $started = true; }
        return $len;
    }
    $p = strpos($line, ':');
    if ($p === false) { return $len; }
    $name = trim(substr($line, 0, $p));
    $value = trim(substr($line, $p + 1));
    $lower = strtolower($name);
    if (in_array($lower, $hop, true)) { return $len; }
    if ($lower === 'location') {
        if (strpos($value, $node) === 0) {
            $value = $base . substr($value, strlen($node));
        } elseif (isset($value[0]) && $value[0] === '/' && (!isset($value[1]) || $value[1] !== '/')) {
            $value = $base . $value;
        }
    }
    header($name . ': ' . $value, !in_array($lower, ['set-cookie', 'vary', 'link'], true));
    return $len;
});
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
    echo $data;
    flush();
    return strlen($data);
});

$ok = curl_exec($ch);
if ($ok === false && !$started) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "openGym's server did not answer (" . curl_error($ch) . "). If you just deployed it, give it a minute.";
}
curl_close($ch);
