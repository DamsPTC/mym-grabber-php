<?php
// unlock/dl.php — renvoie les URL absolues des segments, pour script ou ffmpeg
require __DIR__ . '/lib.php';

$prefix = isset($_GET['prefix']) ? rawurldecode((string)$_GET['prefix']) : '';
$uuid   = isset($_GET['uuid'])   ? rawurldecode((string)$_GET['uuid'])   : '';
$quality = isset($_GET['q'])     ? (string)$_GET['q']                     : '720p';

$prefix = str_replace('..', '', $prefix);
$uuid   = preg_replace('~[^A-Za-z0-9_.\-]~', '', $uuid);
if (!in_array($quality, UNLOCK_QUALITIES, true)) $quality = '720p';

$response = unlock_http(UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid . '_' . $quality . '.m3u8');
if ($response['status'] !== 200) {
    http_response_code(502);
    header('Content-Type: text/plain');
    echo 'playlist indisponible';
    exit;
}

$base = UNLOCK_MEDIA . '/' . $prefix . '/HLS/';
$segments = [];
foreach (preg_split('~\r?\n~', $response['body']) as $line) {
    $line = trim($line);
    if ($line !== '' && $line[0] !== '#') $segments[] = $base . $line;
}

if (isset($_GET['raw'])) {
    header('Content-Type: text/plain');
    echo implode("\n", $segments);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['count' => count($segments), 'segments' => $segments], JSON_UNESCAPED_SLASHES);
