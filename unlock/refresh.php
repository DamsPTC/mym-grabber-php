<?php
// unlock/refresh.php — rafraîchit la session fan et prouve qu'elle sert à quelque chose
//
// Deux modes :
//  - HTTP  : refresh.php?key=...  (à appeler par cron côté hébergeur)
//  - CLI   : php refresh.php
//
// Le login styx exige un reCAPTCHA Enterprise : on réutilise donc l'API quand une
// session existe et, sinon, on le signale. Dans tous les cas la session obtenue
// est vérifiée sur un média réel avant d'être publiée.

require __DIR__ . '/session.php';
require __DIR__ . '/lib.php';

const REFRESH_TOKEN_FILE = UNLOCK_STATE_DIR . '/refresh.token';
const REFRESH_LOGIN_URL  = 'https://styx.mym.fans/v1/users/fan/login';
const REFRESH_TEST_POST  = '395f38c4-6adf-4f67-a5bf-2c1c349a2906';
const REFRESH_TEST_USER  = 'Maximeyedess';
const REFRESH_MIN_INTERVAL = 900; // secondes

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    // Jeton d'appel propre au site, généré au premier usage.
    unlock_state_dir();
    if (!is_file(REFRESH_TOKEN_FILE)) {
        @file_put_contents(REFRESH_TOKEN_FILE, bin2hex(random_bytes(16)), LOCK_EX);
        @chmod(REFRESH_TOKEN_FILE, 0600);
    }
    $expected = trim((string)@file_get_contents(REFRESH_TOKEN_FILE));
    $given = (string)($_GET['key'] ?? $_SERVER['HTTP_X_REFRESH_KEY'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'clé refusée']);
        exit;
    }
}

/** Réponse de refresh, journalisée. */
function refresh_done(array $payload, bool $isCli): void
{
    unlock_session_log('refresh: ' . ($payload['reason'] ?? '?'));
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!$isCli) exit;
}

// Anti-spam : pas deux rafraîchissements rapprochés.
$previous = unlock_read_state(UNLOCK_AUTO_FILE);
$lastTry = (int)($previous['fetched_at'] ?? 0);
if (!$isCli && $lastTry > 0 && (time() - $lastTry) < REFRESH_MIN_INTERVAL && unlock_state_is_fresh($previous)) {
    refresh_done(['ok' => true, 'reason' => 'récent, rien à faire'], $isCli);
}

if (!function_exists('curl_init')) {
    refresh_done(['ok' => false, 'reason' => 'extension curl absente'], $isCli);
}

/** Vérifie qu'une session lit réellement un média verrouillé. */
function refresh_probe(?string $cookie): array
{
    if ($cookie === null) return ['ok' => false, 'reason' => 'pas de cookie'];
    $headers = unlock_media_headers();
    $headers[] = 'Cookie: ' . $cookie;

    $detail = unlock_fetch_post(REFRESH_TEST_USER, REFRESH_TEST_POST);
    if (isset($detail['error'])) return ['ok' => false, 'reason' => 'API ' . $detail['error']];

    foreach (unlock_collect_media($detail['post']) as $media) {
        if (($media['mime_type'] ?? '') !== 'application/x-mpegURL') continue;
        $date = substr((string)($media['created_at'] ?? ''), 0, 10);
        if ($date === '') continue;
        $prefix = unlock_sibling_prefix(REFRESH_TEST_USER, $date);
        if ($prefix === null) continue;
        $uuid = basename($prefix);
        $url = UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid . '_720p.m3u8';
        $r = unlock_http($url, ['headers' => $headers, 'timeout' => 20]);
        return $r['status'] === 200
            ? ['ok' => true, 'reason' => 'lecture média confirmée']
            : ['ok' => false, 'reason' => 'CDN ' . $r['status']];
    }
    return ['ok' => false, 'reason' => 'aucun média de test'];
}

// 1. Session encore bonne : on republie avec un contrôle.
$current = unlock_session_state();
if ($current !== null) {
    $cookie = unlock_cookie_header($current);
    $probe = refresh_probe($cookie);
    if ($probe['ok']) {
        refresh_done(['ok' => true, 'reason' => 'session encore valide', 'source' => $current['source']], $isCli);
    }
    unlock_session_log('session présente mais inopérante: ' . $probe['reason']);
}

// 2. Login par mot de passe : ne fonctionne que si le compte a un mot de passe natif.
$email = (string)getenv('MYM_EMAIL');
$password = (string)getenv('MYM_PASSWORD');
if ($email === '' || $password === '') {
    refresh_done(['ok' => false, 'reason' => 'MYM_EMAIL / MYM_PASSWORD absents et session inopérante'], $isCli);
}

$jar = tempnam(sys_get_temp_dir(), 'mymjar');
$ch = curl_init(REFRESH_LOGIN_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_USERAGENT => UNLOCK_UA,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Platform: fans', 'Accept: application/json', 'Referer: https://mym.fans/'],
    CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
]);
$loginBody = curl_exec($ch);
$loginStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$cookies = [];
if (is_file($jar)) {
    foreach (@file($jar, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || substr($line, 0, 10) === '#HttpOnly_') {
            if (substr($line, 0, 10) === '#HttpOnly_') $line = substr($line, 10);
            else continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) >= 7) $cookies[$parts[5]] = $parts[6];
    }
    @unlink($jar);
}

if ($loginStatus !== 200 || !$cookies) {
    $reason = 'login ' . $loginStatus;
    if (is_string($loginBody) && $loginBody !== '') {
        $decoded = json_decode($loginBody, true);
        if (is_array($decoded) && isset($decoded['message'])) $reason .= ' ' . $decoded['message'];
    }
    refresh_done(['ok' => false, 'reason' => $reason], $isCli);
}

$state = [
    'cookies' => $cookies,
    'account' => $email,
    'fetched_at' => time(),
    'expires_at' => time() + 3600,
];

$probe = refresh_probe(unlock_cookie_header($state));
if (!$probe['ok']) {
    refresh_done(['ok' => false, 'reason' => 'login ok mais ' . $probe['reason']], $isCli);
}

unlock_state_dir();
@file_put_contents(UNLOCK_AUTO_FILE, json_encode($state), LOCK_EX);
@chmod(UNLOCK_AUTO_FILE, 0600);
refresh_done(['ok' => true, 'reason' => 'session renouvelée'], $isCli);
