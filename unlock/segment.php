<?php
// unlock/segment.php — proxy des segments .ts (aucune signature à transmettre)
require __DIR__ . '/lib.php';

$src = isset($_GET['src']) ? rawurldecode((string)$_GET['src']) : '';
if ($src === '' || !unlock_allowed_source($src)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'source invalide';
    exit;
}

$range = isset($_SERVER['HTTP_RANGE']) ? (string)$_SERVER['HTTP_RANGE'] : null;

// Le CDN peut borner les requêtes : on retente une fois avant d'abandonner.
for ($attempt = 0; $attempt < 2; $attempt++) {
    $response = unlock_http($src, ['range' => $range, 'timeout' => 60]);
    if ($response['status'] === 200 || $response['status'] === 206) break;
}

if (!in_array($response['status'], [200, 206], true)) {
    http_response_code($response['status'] ?: 502);
    header('Content-Type: text/plain');
    echo 'CDN ' . (int)$response['status'];
    exit;
}

$isPlaylist = stripos($response['type'], 'mpegurl') !== false || substr($src, -5) === '.m3u8';

if ($isPlaylist) {
    header('Content-Type: application/vnd.apple.mpegurl');
    echo $response['body'];
    exit;
}

http_response_code($response['status'] === 206 ? 206 : 200);
header('Content-Type: video/MP2T');
header('Content-Length: ' . strlen($response['body']));
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');
echo $response['body'];
