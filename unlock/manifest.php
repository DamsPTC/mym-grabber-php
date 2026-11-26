<?php
// unlock/manifest.php — playlist reconstruite avec segments proxifiés
require __DIR__ . '/lib.php';

$prefix = isset($_GET['prefix']) ? rawurldecode((string)$_GET['prefix']) : '';
$uuid   = isset($_GET['uuid'])   ? rawurldecode((string)$_GET['uuid'])   : '';
$quality = isset($_GET['q'])     ? (string)$_GET['q']                     : '720p';

$prefix = str_replace('..', '', $prefix);
$uuid   = preg_replace('~[^A-Za-z0-9_.\-]~', '', $uuid);
if (!in_array($quality, UNLOCK_QUALITIES, true)) $quality = '720p';

if ($prefix === '' || $uuid === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'paramètres manquants';
    exit;
}

$playlist = unlock_rewrite_playlist(UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid . '_' . $quality . '.m3u8');

header('Content-Type: application/vnd.apple.mpegurl');
header('Content-Disposition: attachment; filename="' . $uuid . '_' . $quality . '.m3u8"');
echo $playlist;
