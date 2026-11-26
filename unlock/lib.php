<?php
// unlock/lib.php — déverrouillage du contenu gated MYM
//
// Chaîne établie par audit :
//  1. La page publique du post expose une URL signée du CDN média dans le DOM.
//     Son chemin porte le vrai préfixe S3 : /medias/AAAA/MM/JJ/{uuid}/HLS/{uuid}...
//  2. Sur ce préfixe, la playlist variante .m3u8 et tous les segments .ts sont
//     servis SANS Policy/Signature/Key-Pair-Id — alors que la playlist master et
//     les images renvoient 403 MissingKey.
//  3. Le player du site résout ce chemin sans appel API dédié : le chemin signé
//     est la seule source fiable (le champ `filename` de l'API est parfois
//     décorrélé du dossier réel).
//
// Repli quand aucune URL signée n'est trouvée : préfixe reconstruit depuis le
// descripteur base64url de thumbnail_url.

const UNLOCK_API   = 'https://styx.mym.fans';
const UNLOCK_WEB   = 'https://mym.fans';
const UNLOCK_MEDIA = 'https://media.prod-v2.mym.fans';
const UNLOCK_QUALITIES = ['1080p', '720p', '480p', '360p'];
const UNLOCK_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36';

/** Requête HTTP générique. */
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

    return [
        'status' => $status,
        'body'   => $responseBody === false ? '' : $responseBody,
        'type'   => $type,
        'error'  => $error,
    ];
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

/** Base64url -> JSON (padding à restaurer, les descripteurs sont non paddés). */
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

/**
 * Préfixe S3 trouvé dans une URL CDN, quel que soit son habillage.
 * Accepte /medias/v2/{b64} comme les chemins directs /medias/AAAA/MM/JJ/{uuid}/HLS/...
 */
function unlock_prefix_from_url(string $url): ?string
{
    if (strpos($url, UNLOCK_MEDIA . '/') !== 0) return null;

    // Chemin direct déjà résolu : on ne garde que jusqu'à /HLS/.
    if (preg_match('~/medias/\d{4}/\d{2}/\d{2}/([^/?]+)/HLS/~', $url, $m)) {
        return 'medias/' . substr($url, strlen(UNLOCK_MEDIA . '/medias/'), strlen($url))
            === '' ? null : preg_replace('~/HLS/.*$~', '', substr($url, strlen(UNLOCK_MEDIA . '/')));
    }

    // Descripteur base64url v2.
    if (preg_match('~/medias/v2/([^?]+)~', $url, $m)) {
        $descriptor = unlock_decode_descriptor($m[1]);
        $key = is_array($descriptor) ? (string)($descriptor['key'] ?? '') : '';
        if ($key !== '' && strpos($key, '/thumbnails/') !== false) {
            return explode('/thumbnails/', $key)[0];
        }
    }
    return null;
}

/**
 * Récupère l'URL HLS signée du post sur sa page publique.
 * La page expose l'URL complète ; le chemin suffit, la signature est ignorée.
 */
function unlock_page_playlist(string $username, string $postId): ?string
{
    $url = UNLOCK_WEB . '/' . rawurlencode($username) . '?tab=posts&media=' . rawurlencode($postId);
    $response = unlock_http($url, [
        'headers' => ['Accept: text/html', 'Referer: ' . UNLOCK_WEB . '/'],
        'timeout' => 25,
    ]);
    if ($response['status'] !== 200) return null;

    if (!preg_match_all('~https://media\.prod-v2\.mym\.fans/medias/[^"\'\\\\\s<>]+\.m3u8[^"\'\\\\\s<>]*~', $response['body'], $matches)) {
        return null;
    }
    // On préfère une variante (…_720p.m3u8) à la master.
    foreach ($matches[0] as $candidate) {
        if (preg_match('~_\d{3,4}p\.m3u8~', $candidate)) return $candidate;
    }
    return $matches[0][0] ?? null;
}

/** Tous les médias du post (bloc media[] imbriqué, sinon medias[]). */
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

/**
 * Résout un post : gate, qualité disponible, playlist.
 * Ne renvoie jamais d'URL signée au navigateur.
 */
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

    $medias = unlock_collect_media($post);
    $hasVideo = false;
    foreach ($medias as $media) if (($media['mime_type'] ?? '') === 'application/x-mpegURL') $hasVideo = true;

    // Une seule récupération de page, partagée par les vidéos du post.
    $pagePlaylist = $hasVideo ? unlock_page_playlist($username, $postId) : null;

    $videoIndex = 0;
    foreach ($medias as $media) {
        $entry = [
            'kind'     => (string)($media['type'] ?? 'unknown'),
            'mime'     => (string)($media['mime_type'] ?? ''),
            'duration' => $media['duration'] ?? null,
        ];

        if (($media['mime_type'] ?? '') === 'application/x-mpegURL') {
            $prefix = null;

            // Source 1 : le chemin de la playlist publiée par la page.
            if ($pagePlaylist !== null) {
                $base = preg_replace('~_\d{3,4}p\.m3u8.*$~', '', $pagePlaylist);
                if ($videoIndex > 0) $base .= ''; // une variante par vidéo, la première suffit
                $prefix = preg_replace('~^' . preg_quote(UNLOCK_MEDIA . '/', '~') . '~', '', $base);
                $prefix = preg_replace('~/HLS/[^/]*$~', '', $prefix);
            }
            // Source 2 : descripteur de la vignette.
            if ($prefix === null) $prefix = unlock_prefix_from_url((string)($media['thumbnail_url'] ?? ''));

            $videoIndex++;

            if ($prefix === null) {
                $entry['state'] = 'chemin introuvable';
                $result['items'][] = $entry;
                continue;
            }

            $uuid = basename($prefix);
            $baseUrl = UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid;
            $quality = null;
            foreach (UNLOCK_QUALITIES as $candidate) {
                $probe = unlock_http($baseUrl . '_' . $candidate . '.m3u8', ['timeout' => 15]);
                if ($probe['status'] === 200) { $quality = $candidate; break; }
            }
            if ($quality === null) {
                $entry['state'] = 'aucune variante accessible';
                $result['items'][] = $entry;
                continue;
            }

            $entry['state']   = 'accessible';
            $entry['quality'] = $quality;
            $entry['prefix']  = $prefix;
            $entry['uuid']    = $uuid;
            $entry['playlist_abs'] = unlock_absolutize_playlist($baseUrl . '_' . $quality . '.m3u8');
            $result['items'][] = $entry;
            continue;
        }

        $entry['state'] = 'image';
        $entry['thumb'] = (string)($media['thumbnail_url'] ?? '');
        $result['items'][] = $entry;
    }

    if (!$result['items']) $result['items'][] = ['state' => 'aucun média dans la réponse'];
    return $result;
}

/** [prefixe, lignes] d'une playlist brute. */
function unlock_playlist_lines(string $playlistUrl): array
{
    $response = unlock_http($playlistUrl, ['timeout' => 20]);
    if ($response['status'] !== 200) return [null, []];
    $prefix = '';
    if (preg_match('~^(https://[^/]+/.*/)HLS/~', $playlistUrl, $match)) $prefix = $match[1] . 'HLS/';
    return [$prefix, preg_split('~\r?\n~', trim($response['body']))];
}

/** Playlist à segments absolus (data-URI / hls.js). */
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

/** Playlist à segments proxifiés (téléchargement via ffmpeg). */
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
