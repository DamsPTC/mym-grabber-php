<?php
// unlock/lib.php — déverrouillage du contenu gated MYM
//
// FINDINGS d'audit exploités :
//  A. GET /v1/creators/{username}/posts/{postId} répond 200 et expose gate,
//     clearance, durée et vignette même pour gate="subscription", sans abonnement.
//  B. Sur media.prod-v2.mym.fans, le préfixe /medias/AAAA/MM/JJ/{uuid}/HLS/ sert
//     la playlist variante .m3u8 et tous les segments .ts SANS Policy/Signature,
//     alors que la playlist master et les images renvoient 403 MissingKey.
//
// `created_at` est porté par le MÉDIA. Sur un post verrouillé, `url` reste null
// et la vignette est à clé plate ; le dossier S3 se déduit alors d'un média libre
// publié le même jour (le player MYM fait de même : le dossier est partagé par
// upload). Le CDN exige un Referer mym.fans pour servir les segments.

const UNLOCK_API   = 'https://styx.mym.fans';
const UNLOCK_WEB   = 'https://mym.fans';
const UNLOCK_MEDIA = 'https://media.prod-v2.mym.fans';
const UNLOCK_QUALITIES = ['1080p', '720p', '480p', '360p'];
const UNLOCK_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36';

function unlock_http(string $url, array $options = []): array
{
    $method  = $options['method']  ?? 'GET';
    $headers = $options['headers'] ?? [];
    $body    = $options['body']    ?? null;
    $timeout = $options['timeout'] ?? 30;
    $range   = $options['range']   ?? null;

    $ch = curl_init($url);
    $set = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => UNLOCK_UA,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($method === 'POST') {
        $set[CURLOPT_POST] = true;
        if ($body !== null) $set[CURLOPT_POSTFIELDS] = $body;
    }
    if ($range !== null) $set[CURLOPT_RANGE] = $range;
    curl_setopt_array($ch, $set);

    $responseBody = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error  = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => $responseBody === false ? '' : $responseBody, 'type' => $type, 'error' => $error];
}

/** En-têtes attendus par le CDN média. */
function unlock_media_headers(string $extra = ''): array
{
    $headers = [
        'Referer: ' . UNLOCK_WEB . '/',
        'Origin: ' . UNLOCK_WEB,
        'Accept: */*',
    ];
    if ($extra !== '') $headers[] = $extra;
    return $headers;
}

function unlock_sanitize_user(string $username): string
{
    $username = trim($username);
    $username = preg_replace('~[^A-Za-z0-9_.\-]~', '', $username);
    return substr($username, 0, 60);
}

function unlock_is_uuid(string $value): bool
{
    return (bool)preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $value);
}

function unlock_decode_descriptor(string $descriptor): ?array
{
    $normalized = strtr($descriptor, '-_', '+/');
    $remainder = strlen($normalized) % 4;
    if ($remainder > 0) $normalized .= str_repeat('=', 4 - $remainder);
    $raw = base64_decode($normalized, true);
    if ($raw === false || $raw === '') return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function unlock_prefix_from_url(string $url): ?string
{
    if ($url === '' || strpos($url, UNLOCK_MEDIA . '/') !== 0) return null;
    if (preg_match('~^https://[^/]+/(medias/\d{4}/\d{2}/\d{2}/[^/?]+)/HLS/~', $url, $m)) return $m[1];
    if (preg_match('~/medias/v2/([^?]+)~', $url, $m)) {
        $descriptor = unlock_decode_descriptor($m[1]);
        $key = is_array($descriptor) ? (string)($descriptor['key'] ?? '') : '';
        if ($key !== '' && strpos($key, '/thumbnails/') !== false) return explode('/thumbnails/', $key)[0];
    }
    return null;
}

function unlock_collect_media(array $post): array
{
    $collected = [];
    foreach (($post['medias'] ?? []) as $group) {
        $nested = $group['media'] ?? null;
        if (is_array($nested) && $nested) {
            foreach ($nested as $item) if (is_array($item)) $collected[] = $item;
            continue;
        }
        $collected[] = $group;
    }
    return $collected;
}

function unlock_fetch_post(string $username, string $postId): array
{
    $response = unlock_http(
        UNLOCK_API . '/v1/creators/' . rawurlencode($username) . '/posts/' . rawurlencode($postId),
        ['headers' => ['X-Platform: fans', 'Accept: application/json']]
    );
    if ($response['status'] !== 200) return ['error' => 'post HTTP ' . $response['status']];
    $payload = json_decode($response['body'], true);
    if (!is_array($payload) || !isset($payload['data'])) return ['error' => 'réponse illisible'];
    return ['post' => $payload['data']];
}

function unlock_list_posts(string $username, int $limit = 30): array
{
    $response = unlock_http(
        UNLOCK_API . '/v1/creators/' . rawurlencode($username) . '/posts?limit=' . $limit,
        ['headers' => ['X-Platform: fans', 'Accept: application/json']]
    );
    if ($response['status'] !== 200) return ['error' => 'liste HTTP ' . $response['status']];
    $payload = json_decode($response['body'], true);
    return is_array($payload) ? ($payload['data'] ?? []) : [];
}

/** Préfixe d'un média libre publié le même jour (AAAA-MM-JJ). */
function unlock_sibling_prefix(string $username, string $date, int $maxProbes = 25): ?string
{
    $listed = unlock_list_posts($username, 40);
    if (isset($listed['error'])) return null;

    $probes = 0;
    foreach ($listed as $post) {
        if (++$probes > $maxProbes) break;

        $detail = unlock_fetch_post($username, (string)$post['id']);
        if (isset($detail['error'])) continue;

        foreach (unlock_collect_media($detail['post']) as $media) {
            if (($media['mime_type'] ?? '') !== 'application/x-mpegURL') continue;
            if (substr((string)($media['created_at'] ?? ''), 0, 10) !== $date) continue;
            $prefix = unlock_prefix_from_url((string)($media['url'] ?? ''));
            if ($prefix !== null) return $prefix;
        }
    }
    return null;
}

/** Première qualité réellement servie pour un préfixe. */
function unlock_first_quality(string $prefix, string $uuid): ?string
{
    $base = UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid;
    foreach (UNLOCK_QUALITIES as $candidate) {
        $probe = unlock_http($base . '_' . $candidate . '.m3u8', ['timeout' => 15, 'headers' => unlock_media_headers()]);
        if ($probe['status'] === 200) return $candidate;
    }
    return null;
}

function unlock_resolve(string $username, string $postId): array
{
    $username = unlock_sanitize_user($username);
    if ($username === '' || !unlock_is_uuid($postId)) return ['error' => 'paramètres invalides'];

    $fetched = unlock_fetch_post($username, $postId);
    if (isset($fetched['error'])) return $fetched;
    $post = $fetched['post'];

    $result = [
        'username'  => $username,
        'postId'    => $postId,
        'gate'      => (string)($post['gate'] ?? 'unknown'),
        'clearance' => (string)($post['clearance'] ?? 'unknown'),
        'locked'    => (($post['gate'] ?? 'none') !== 'none'),
        'items'     => [],
    ];

    $siblingCache = [];

    foreach (unlock_collect_media($post) as $media) {
        $entry = ['kind' => (string)($media['type'] ?? 'unknown'), 'mime' => (string)($media['mime_type'] ?? ''), 'duration' => $media['duration'] ?? null];

        if (($media['mime_type'] ?? '') !== 'application/x-mpegURL') {
            $entry['state'] = 'image';
            $entry['thumb'] = (string)($media['thumbnail_url'] ?? '');
            $result['items'][] = $entry;
            continue;
        }

        $date = substr((string)($media['created_at'] ?? ''), 0, 10);
        $prefix = unlock_prefix_from_url((string)($media['url'] ?? ''));

        if ($prefix === null && $date !== '') {
            if (!array_key_exists($date, $siblingCache)) {
                $siblingCache[$date] = unlock_sibling_prefix($username, $date);
            }
            $prefix = $siblingCache[$date];
        }

        if ($prefix === null) {
            $entry['state'] = $date !== ''
                ? 'aucun média libre publié le ' . $date . ' pour déduire le dossier'
                : 'date de publication absente du média';
            $result['items'][] = $entry;
            continue;
        }

        $uuid = basename($prefix);
        $quality = unlock_first_quality($prefix, $uuid);
        if ($quality === null) {
            $entry['state'] = 'dossier ' . $prefix . ' déduit mais aucune variante accessible';
            $result['items'][] = $entry;
            continue;
        }

        $base = UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid;
        $entry['state'] = 'accessible';
        $entry['quality'] = $quality;
        $entry['prefix'] = $prefix;
        $entry['uuid'] = $uuid;
        $entry['playlist_abs'] = unlock_absolutize_playlist($base . '_' . $quality . '.m3u8');
        $result['items'][] = $entry;
    }

    if (!$result['items']) $result['items'][] = ['state' => 'aucun média dans la réponse'];
    return $result;
}

function unlock_playlist_lines(string $playlistUrl): array
{
    $response = unlock_http($playlistUrl, ['timeout' => 20, 'headers' => unlock_media_headers()]);
    if ($response['status'] !== 200) return [null, []];
    $prefix = '';
    if (preg_match('~^(https://[^/]+/.*/)HLS/~', $playlistUrl, $match)) $prefix = $match[1] . 'HLS/';
    return [$prefix, preg_split('~\r?\n~', trim($response['body']))];
}

function unlock_absolutize_playlist(string $playlistUrl): string
{
    [$prefix, $lines] = unlock_playlist_lines($playlistUrl);
    if ($prefix === null) return '';
    $out = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') { $out[] = $line; continue; }
        $out[] = preg_match('~^https?://~', $trimmed) ? $trimmed : $prefix . $trimmed;
    }
    return implode("\n", $out);
}

function unlock_rewrite_playlist(string $playlistUrl): string
{
    [$prefix, $lines] = unlock_playlist_lines($playlistUrl);
    if ($prefix === null) return '';
    $out = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#') { $out[] = $line; continue; }
        $absolute = preg_match('~^https?://~', $trimmed) ? $trimmed : $prefix . $trimmed;
        $out[] = unlock_script_relative('segment.php') . '?src=' . rawurlencode($absolute);
    }
    return implode("\n", $out);
}

function unlock_script_relative(string $script): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $dir . '/' . $script;
}

function unlock_allowed_source(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    $path = (string)parse_url($url, PHP_URL_PATH);
    return $host === parse_url(UNLOCK_MEDIA, PHP_URL_HOST)
        && strpos($path, '/medias/') === 0
        && !preg_match('~\.\.~', $path);
}
