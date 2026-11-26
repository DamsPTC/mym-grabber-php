<?php
// unlock/debug.php — la page publique contient-elle l'URL HLS ?
require __DIR__ . '/lib.php';

$username = isset($_GET['u']) ? unlock_sanitize_user((string)$_GET['u']) : 'Maximeyedess';
$postId   = isset($_GET['p']) ? trim((string)$_GET['p']) : '395f38c4-6adf-4f67-a5bf-2c1c349a2906';

header('Content-Type: application/json; charset=utf-8');
$url = UNLOCK_WEB . '/' . rawurlencode($username) . '?tab=posts&media=' . rawurlencode($postId);
$r = unlock_http($url, ['headers' => ['Accept: text/html'], 'timeout' => 25]);

$out = ['pageStatus' => $r['status'], 'pageLen' => strlen($r['body'])];
$body = $r['body'];

$out['hasHlsLiteral'] = substr_count($body, '/HLS/');
$out['hasMediaHost'] = substr_count($body, 'media.prod-v2.mym.fans');
$out['hasEscaped'] = substr_count($body, 'media.prod-v2.mym.fans\\/');
$out['hasM3u8'] = substr_count($body, '.m3u8');

// échantillons bruts autour des occurrences
$samples = [];
$offset = 0;
for ($i = 0; $i < 3; $i++) {
    $pos = strpos($body, '/HLS/', $offset);
    if ($pos === false) break;
    $samples[] = substr($body, max(0, $pos - 90), 190);
    $offset = $pos + 5;
}
$out['samples'] = $samples;

// regex actuels
$out['regexSlash'] = preg_match_all('~https://media\.prod-v2\.mym\.fans/medias/[^"\'\\\\\s<>]+\.m3u8[^"\'\\\\\s<>]*~', $body);
$out['regexSlashed'] = preg_match_all('~https://media\.prod-v2\.mym\.fans\\\\/medias\\\\/[^"\'\\\\\s<>]+\.m3u8~', $body);

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
