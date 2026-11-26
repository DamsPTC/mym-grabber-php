<?php
// unlock/debug.php — diagnostic du décodage du descripteur CDN
require __DIR__ . '/lib.php';

$username = isset($_GET['u']) ? unlock_sanitize_user((string)$_GET['u']) : 'Maximeyedess';
$postId   = isset($_GET['p']) ? trim((string)$_GET['p']) : '395f38c4-6adf-4f67-a5bf-2c1c349a2906';

header('Content-Type: application/json; charset=utf-8');
$out = ['php' => PHP_VERSION, 'curl' => function_exists('curl_init')];

$fetched = unlock_fetch_post($username, $postId);
if (isset($fetched['error'])) { echo json_encode($out + ['error' => $fetched['error']]); exit; }

$media = $fetched['post']['medias'][0] ?? [];
$thumb = (string)($media['thumbnail_url'] ?? '');
$out['mime'] = $media['mime_type'] ?? null;
$out['thumbLen'] = strlen($thumb);
$out['thumbHead'] = substr($thumb, 0, 70);

$matched = preg_match('~/medias/v2/([^?]+)~', $thumb, $m);
$out['regexMatches'] = $matched;
$desc = $m[1] ?? '';
$out['descLen'] = strlen($desc);
$out['descTail'] = substr($desc, -6);

$normalized = strtr($desc, '-_', '+/');
$remainder = strlen($normalized) % 4;
if ($remainder > 0) $normalized .= str_repeat('=', 4 - $remainder);
$out['normalizedLen'] = strlen($normalized);

$raw = base64_decode($normalized, true);
$out['strictDecoded'] = $raw === false ? 'FALSE' : strlen($raw);

if ($raw !== false && $raw !== '') {
    $out['rawFirstBytes'] = bin2hex(substr($raw, 0, 4));
    $json = json_decode($raw, true);
    $out['jsonOk'] = is_array($json);
    $out['jsonErr'] = json_last_error_msg();
    $out['key'] = is_array($json) ? ($json['key'] ?? null) : null;
}

$out['prefix'] = unlock_media_prefix($media);
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
