<?php
// unlock/debug.php — diagnostic: où trouver le préfixe S3 réellement ?
require __DIR__ . '/lib.php';

$username = isset($_GET['u']) ? unlock_sanitize_user((string)$_GET['u']) : 'Maximeyedess';
$postId   = isset($_GET['p']) ? trim((string)$_GET['p']) : '395f38c4-6adf-4f67-a5bf-2c1c349a2906';

header('Content-Type: application/json; charset=utf-8');

$fetched = unlock_fetch_post($username, $postId);
if (isset($fetched['error'])) { echo json_encode(['error' => $fetched['error']]); exit; }

$out = ['candidates' => []];

foreach (($fetched['post']['medias'] ?? []) as $media) {
    foreach (['thumbnail_url', 'blurry_url', 'url'] as $field) {
        $value = (string)($media[$field] ?? '');
        if ($value === '') continue;
        $entry = ['field' => $field, 'len' => strlen($value)];
        if (preg_match('~/medias/v2/([^?]+)~', $value, $m)) {
            $desc = unlock_decode_descriptor($m[1]);
            $entry['key'] = is_array($desc) ? ($desc['key'] ?? null) : 'DECODE_FAIL';
            $entry['hasThumbDir'] = is_array($desc) && strpos((string)($desc['key'] ?? ''), '/thumbnails/') !== false;
        } else {
            $entry['raw'] = substr($value, 0, 90);
        }
        $out['candidates'][] = $entry;
    }
}

// essai de reconstruction par date + nom de fichier du média
foreach (($fetched['post']['medias'] ?? []) as $media) {
    $name = (string)($media['filename'] ?? '');
    $created = (string)($media['created_at'] ?? '');
    if ($name === '' || $created === '') continue;
    $base = preg_replace('~\.m3u8$~', '', $name);
    $datePath = str_replace('-', '/', substr($created, 0, 10));
    $guesses = [
        'date+filename' => UNLOCK_MEDIA . '/medias/' . $datePath . '/' . $base . '/HLS/' . $base . '_720p.m3u8',
        'date+thumbname' => null,
    ];
    if (preg_match('~^(\d{14})-([A-Za-z0-9]+)~', $base, $mm)) {
        $guesses['date+folder'] = UNLOCK_MEDIA . '/medias/' . $datePath . '/' . $base . '/HLS/' . $base . '_720p.m3u8';
    }
    $out['guesses'] = [];
    foreach ($guesses as $label => $url) {
        if ($url === null) continue;
        $r = unlock_http($url, ['timeout' => 12]);
        $out['guesses'][$label] = $r['status'] . ' ' . substr($url, -70);
    }
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
