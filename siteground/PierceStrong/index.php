<?php
/* openGym pass-through for a SiteGround website.
 *
 * Put this folder (index.php, .htaccess, config.php) on your website, for example at
 * public_html/PierceStrong/. Every request under that folder is forwarded to the openGym
 * Node.js project, and the answer is passed back. To your browser the whole app lives at
 * https://your-site/PierceStrong/, which passkey sign-in needs: the app and its API on the same
 * address as the page.
 *
 * Security, in short:
 *  - It only ever talks to the one address in config.php, over HTTPS with the certificate checked,
 *    and never follows redirects, so it cannot be used to reach anything else.
 *  - Only normal web methods, sane paths and bounded body sizes are passed on.
 *  - Headers a visitor could use to pose as the proxy or fake their address are dropped and set
 *    here instead. The shared secret is added here and never sent back to the visitor.
 *  - Errors say nothing about the setup; details go to the PHP error log only.
 *
 * Settings are in config.php (copy config.sample.php). Nothing else to change here.
 */

const MAX_BODY_BYTES = 50 * 1024 * 1024;   // custom-exercise videos are capped at 40 MB by the app
const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

function fail(int $code, string $message): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
    }
    echo $message;
    exit;
}

header_remove('X-Powered-By');

$config = @include __DIR__ . '/config.php';
$node = is_array($config) ? rtrim((string)($config['node_url'] ?? ''), '/') : '';
$secret = is_array($config) ? (string)($config['secret'] ?? '') : '';
$nodeParts = parse_url($node);
$nodeIsLocal = isset($nodeParts['host']) && in_array($nodeParts['host'], ['127.0.0.1', 'localhost'], true);
if (!$nodeParts || empty($nodeParts['host']) || !empty($nodeParts['path']) || !empty($nodeParts['query'])
    || !empty($nodeParts['user']) || !in_array($nodeParts['scheme'] ?? '', $nodeIsLocal ? ['http', 'https'] : ['https'], true)
    || strlen($secret) < 32 || strpos($secret, 'REPLACE') !== false) {
    error_log('openGym pass-through: config.php is missing or invalid (https node_url without a path, secret of 32+ characters)');
    fail(503, 'This app is not set up yet.');
}

// Send bytes straight through: no PHP compression or buffering in the way.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) { ob_end_clean(); }
@set_time_limit(330);

/* ---------- the request ---------- */
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ALLOWED_METHODS, true)) {
    header('Allow: ' . implode(', ', ALLOWED_METHODS));
    fail(405, 'Method not allowed.');
}

// The path below this folder, e.g. /PierceStrong/api/health -> /api/health
$base = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
if (strlen($uri) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) {
    fail(400, 'Bad request.');
}
$rest = ($base !== '' && strncasecmp($uri, $base, strlen($base)) === 0) ? substr($uri, strlen($base)) : $uri;
if ($rest === '' || $rest[0] === '?') {
    // /PierceStrong without the slash: the app's files are relative, so it needs the slash.
    header('Location: ' . $base . '/' . $rest, true, 301);
    exit;
}
if ($rest[0] !== '/' || strncmp($rest, '//', 2) === 0) {
    fail(400, 'Bad request.');
}

// Body: refuse oversized ones before reading anything.
$body = null;
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    $declared = isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '' ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($declared > MAX_BODY_BYTES) { fail(413, 'That upload is too large.'); }
    $body = (string)file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if (strlen($body) > MAX_BODY_BYTES) { fail(413, 'That upload is too large.'); }
}

/* ---------- request headers to pass on: only the ones the app needs ---------- */
$pass = ['accept', 'accept-language', 'authorization', 'cache-control', 'content-type', 'cookie',
         'if-match', 'if-modified-since', 'if-none-match', 'if-range', 'origin', 'pragma', 'range',
         'referer', 'sec-fetch-dest', 'sec-fetch-mode', 'sec-fetch-site', 'user-agent',
         'x-requested-with', 'cf-access-client-id', 'cf-access-client-secret',
         'access-control-request-method', 'access-control-request-headers'];
$incoming = function_exists('getallheaders') ? getallheaders() : [];
if (!$incoming) {
    foreach ($_SERVER as $k => $v) {
        if (strncmp($k, 'HTTP_', 5) === 0) { $incoming[str_replace('_', '-', substr($k, 5))] = $v; }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) { $incoming['Content-Type'] = $_SERVER['CONTENT_TYPE']; }
}
$headers = [];
foreach ($incoming as $name => $value) {
    $lower = strtolower((string)$name);
    if (!in_array($lower, $pass, true)) { continue; }
    if (preg_match('/[\r\n\x00]/', (string)$value) || strlen((string)$value) > 16384) { fail(400, 'Bad request.'); }
    $headers[] = $name . ': ' . $value;
}
$client = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!filter_var($client, FILTER_VALIDATE_IP)) { $client = '0.0.0.0'; }
$headers[] = 'X-Forwarded-For: ' . $client;
$headers[] = 'X-Forwarded-Proto: https';
$headers[] = 'X-OpenGym-Proxy: ' . $secret;
// The real path travels in a header, and the request itself always goes to one fixed address on
// the Node.js project. SiteGround's front end refuses some paths outright (anything ending in
// /config, for one), and the app has routes like that; this keeps such filters out of the way.
$headers[] = 'X-OpenGym-Path: ' . $rest;
$headers[] = 'Expect:';            // no "100 Continue" round trip
$headers[] = 'Accept-Encoding:';   // plain bytes back, so lengths and ranges stay exact

/* ---------- forward ---------- */
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $node . '/__opengym',
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS      => 0,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 300,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_PROTOCOLS      => $nodeIsLocal ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
]);
if (defined('CURLOPT_REDIR_PROTOCOLS')) { curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS); }
if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
} elseif ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$started = false;
$drop = ['connection', 'keep-alive', 'transfer-encoding', 'te', 'trailer', 'upgrade', 'proxy-connection',
         'server', 'x-powered-by', 'alt-svc', 'x-proxy-cache', 'x-opengym-proxy'];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$started, $drop, $base, $node) {
    $len = strlen($line);
    $line = rtrim($line, "\r\n");
    if ($line === '') { return $len; }
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
        $code = (int)$m[1];
        if ($code >= 200) { http_response_code($code); $started = true; }
        return $len;
    }
    $p = strpos($line, ':');
    if ($p === false) { return $len; }
    $name = trim(substr($line, 0, $p));
    $value = trim(substr($line, $p + 1));
    $lower = strtolower($name);
    if (in_array($lower, $drop, true) || !preg_match('/^[A-Za-z0-9-]+$/', $name)) { return $len; }
    if ($lower === 'location') {
        // Keep redirects on this website, under this folder.
        if (strncasecmp($value, $node, strlen($node)) === 0) {
            $value = $base . substr($value, strlen($node));
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $value)) {
            return $len;   // a redirect to anywhere else is not passed on
        } elseif (isset($value[0]) && $value[0] === '/') {
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
if ($ok === false) {
    error_log('openGym pass-through: ' . curl_error($ch));
    if (!$started) { fail(502, "The app's server did not answer. If it was just deployed, try again in a minute."); }
}
curl_close($ch);
