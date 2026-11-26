<?php
// unlock/session-set.php — dépôt manuel d'un cookie de session (secours)
//
// Reçoit en POST un en-tête Cookie brut (ou du JSON {cookies:{...}}), le valide
// sur un média réel et l'écrit dans session.manual.txt. Un jeton d'écriture
// protège l'endpoint : sans lui, n'importe qui écraserait la session du site.

require __DIR__ . '/session.php';
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

$expected = trim((string)@file_get_contents(UNLOCK_STATE_DIR . '/refresh.token'));
if ($expected === '' && is_dir(UNLOCK_STATE_DIR)) {
    // Le jeton est créé par refresh.php ; on tolère son absence en CLI uniquement.
}
$given = (string)($_POST['key'] ?? $_GET['key'] ?? $_SERVER['HTTP_X_REFRESH_KEY'] ?? '');
if (PHP_SAPI !== 'cli' && ($expected === '' || !hash_equals($expected, $given))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'clé refusée']);
    exit;
}

$raw = (string)($_POST['cookie'] ?? $_GET['cookie'] ?? '');
if ($raw === '' && isset($_POST['json'])) $raw = (string)$_POST['json'];
if (trim($raw) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cookie vide']);
    exit;
}

/** Accepte l'en-tête "a=1; b=2" ou un JSON {cookies:{a:1}}. */
$cookies = [];
$decoded = json_decode($raw, true);
if (is_array($decoded) && isset($decoded['cookies']) && is_array($decoded['cookies'])) {
    foreach ($decoded['cookies'] as $name => $value) {
        if (is_string($name) && is_string($value)) $cookies[$name] = $value;
    }
} else {
    foreach (explode(';', $raw) as $pair) {
        $pair = trim($pair);
        if ($pair === '' || strpos($pair, '=') === false) continue;
        [$name, $value] = explode('=', $pair, 2);
        $name = trim($name);
        if ($name !== '') $cookies[$name] = trim($value);
    }
}

if (!$cookies) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'aucun cookie exploitable']);
    exit;
}

$state = [
    'cookies' => $cookies,
    'account' => 'cookie manuel',
    'fetched_at' => time(),
    'expires_at' => time() + 3600,
];

$headers = unlock_media_headers();
$headers[] = 'Cookie: ' . unlock_cookie_header($state);

// Vérification immédiate sur un média verrouillé connu.
$detail = unlock_fetch_post('Maximeyedess', '395f38c4-6adf-4f67-a5bf-2c1c349a2906');
$probe = ['ok' => false, 'reason' => 'détail indisponible'];
if (!isset($detail['error'])) {
    foreach (unlock_collect_media($detail['post']) as $media) {
        if (($media['mime_type'] ?? '') !== 'application/x-mpegURL') continue;
        $date = substr((string)($media['created_at'] ?? ''), 0, 10);
        $prefix = $date !== '' ? unlock_sibling_prefix('Maximeyedess', $date) : null;
        if ($prefix === null) continue;
        $uuid = basename($prefix);
        $r = unlock_http(UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid . '_720p.m3u8', ['headers' => $headers, 'timeout' => 20]);
        $probe = $r['status'] === 200
            ? ['ok' => true, 'reason' => 'lecture média confirmée']
            : ['ok' => false, 'reason' => 'CDN ' . $r['status']];
        break;
    }
}

unlock_state_dir();
if ($probe['ok']) {
    @file_put_contents(UNLOCK_MANUAL_FILE, json_encode($state), LOCK_EX);
    @chmod(UNLOCK_MANUAL_FILE, 0600);
    unlock_session_log('cookie manuel accepté (' . count($cookies) . ' cookies)');
} else {
    unlock_session_log('cookie manuel refusé: ' . $probe['reason']);
}

echo json_encode([
    'ok' => $probe['ok'],
    'reason' => $probe['reason'],
    'cookies' => array_keys($cookies),
    'stored' => $probe['ok'],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
