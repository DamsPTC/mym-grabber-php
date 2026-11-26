<?php
// unlock/debug.php — curl du serveur vers le CDN média
require __DIR__ . '/lib.php';

$prefix = isset($_GET['prefix']) ? (string)$_GET['prefix'] : 'medias/2022/10/19/20221019093038-lN-3VuUTiEResqCCnTFEuj08';
$uuid = basename($prefix);
$url = UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid . '_720p.m3u8';

header('Content-Type: application/json; charset=utf-8');
$out = ['url' => $url];

foreach ([
    'sans-entetes' => [],
    'referer' => ['Referer: https://mym.fans/'],
    'referer+origin' => ['Referer: https://mym.fans/', 'Origin: https://mym.fans'],
    'navigateur' => unlock_media_headers(),
] as $label => $headers) {
    $r = unlock_http($url, ['headers' => $headers, 'timeout' => 15]);
    $out[$label] = ['status' => $r['status'], 'type' => $r['type'], 'body' => substr($r['body'], 0, 90), 'err' => $r['error']];
}

$out['qualites'] = [];
foreach (UNLOCK_QUALITIES as $q) {
    $probe = unlock_http(UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid . '_' . $q . '.m3u8', ['timeout' => 12, 'headers' => unlock_media_headers()]);
    $out['qualites'][$q] = $probe['status'];
}

$out['seg'] = unlock_http(UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid . '_720p_00001.ts', ['timeout' => 20, 'headers' => unlock_media_headers(), 'range' => 'bytes=0-99'])['status'];

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
