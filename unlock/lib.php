<?php
// unlock/lib.php — déverrouillage du contenu gated (API JSON styx + HLS non signé)
//
// FINDING 1 : GET /v1/creators/{username}/posts/{postId} répond 200 et expose
//   les médias complets, y compris quand gate="subscription" et clearance="required",
//   sans abonnement et sans session.
// FINDING 2 : sur media.prod-v2.mym.fans, la playlist .m3u8 variante et tous les
//   segments .ts du préfixe /HLS/ sont servis SANS Policy/Signature/Key-Pair-Id.
//
// ATTENTION À LA FORME DU PAYLOAD : un média verrouillé porte deux blocs.
//   - medias[].teaser : vignette de teasing, clé plate medias/AAAA/MM/JJ/{fichier}.jpg
//   - medias[].media[] : le média réel, clé en dossier medias/AAAA/MM/JJ/{uuid}/thumbnails/...
// Seul le second porte le préfixe S3 exploitable pour reconstruire le HLS.

const UNLOCK_API   = 'https://styx.mym.fans';
const UNLOCK_MEDIA = 'https://media.prod-v2.mym.fans';
const UNLOCK_QUALITIES = ['1080p', '720p', '480p', '360p'];
const UNLOCK_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36';

/** Requête HTTP générique, sans cookies : l'API et le CDN sont anonymes. */
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

/**
 * Base64url -> JSON. Les descripteurs CDN sont servis SANS padding : il faut
 * le restaurer, sinon base64_decode() en mode strict échoue.
 */
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
 * Préfixe S3 du média, déduit de la clé encodée dans son thumbnail_url.
 * Ex : medias/2026/08/20/{uuid}/thumbnails/{uuid}.0000000.jpg
 *   ->  medias/2026/08/20/{uuid}
 * Retourne null pour une clé plate (teaser), qui n'a pas de dossier dédié.
 */
function unlock_media_prefix(array $media): ?string
{
    $thumb = (string)($media['thumbnail_url'] ?? '');
    if ($thumb === '') return null;
    if (!preg_match('~/medias/v2/([^?]+)~', $thumb, $match)) return null;
    $descriptor = unlock_decode_descriptor($match[1]);
    $key = is_array($descriptor) ? (string)($descriptor['key'] ?? '') : '';
    if ($key === '' || strpos($key, '/thumbnails/') === false) return null;
    return explode('/thumbnails/', $key)[0];
}

/**
 * Tous les médias réels d'un post : le bloc media[] de chaque medias[],
 * plus medias[] lui-même en repli quand il n'y a pas de sous-tableau.
 */
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

/** Interroge l'API : détail d'un post précis. */
function unlock_fetch_post(string $username, string $postId): array
{
    $response = unlock_http(
        UNLOCK_API . '/v1/creators/' . rawurlencode($username) . '/posts/' . rawurlencode($postId),
        ['headers' => ['X-Platform: fans', 'Accept: application/json']]
    );
    if ($response['status'] !== 200) {
        return ['error' => 'post HTTP ' . $response['status']];
    }
    $payload = json_decode($response['body'], true);
    if (!is_array($payload) || !isset($payload['data'])) {
        return ['error' => 'réponse illisible'];
    }
    return ['post' => $payload['data']];
}

/** Derniers posts d'un créateur, pour lister les contenus verrouillés. */
function unlock_list_posts(string $username, int $limit = 30): array
{
    $response = unlock_http(
        UNLOCK_API . '/v1/creators/' . rawurlencode($username) . '/posts?limit=' . $limit,
        ['headers' => ['X-Platform: fans', 'Accept: application/json']]
    );
    if ($response['status'] !== 200) {
        return ['error' => 'liste HTTP ' . $response['status']];
    }
    $payload = json_decode($response['body'], true);
    return is_array($payload) ? ($payload['data'] ?? []) : [];
}

/**
 * Résolution d'un post : gate, qualité disponible, playlist réécrite.
 * Aucune URL signée n'est exposée au navigateur.
 */
function unlock_resolve(string $username, string $postId): array
{
    $username = unlock_sanitize_user($username);
    if ($username === '' || !unlock_is_uuid($postId)) {
        return ['error' => 'paramètres invalides'];
    }

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
    if (!$medias) {
        $result['items'][] = ['state' => 'aucun média dans la réponse'];
        return $result;
    }

    foreach ($medias as $media) {
        $entry = [
            'kind'     => (string)($media['type'] ?? 'unknown'),
            'mime'     => (string)($media['mime_type'] ?? ''),
            'duration' => $media['duration'] ?? null,
        ];

        if (($media['mime_type'] ?? '') === 'application/x-mpegURL') {
            $prefix = unlock_media_prefix($media);
            if ($prefix === null) {
                $entry['state'] = 'chemin introuvable (descripteur CDN inexploitable)';
                $result['items'][] = $entry;
                continue;
            }
            $uuid = basename($prefix);
            $base = UNLOCK_MEDIA . '/' . $prefix . '/HLS/' . $uuid;

            $quality = null;
            foreach (UNLOCK_QUALITIES as $candidate) {
                $probe = unlock_http($base . '_' . $candidate . '.m3u8', ['timeout' => 15]);
                if ($probe['status'] === 200) { $quality = $candidate; break; }
            }
            if ($quality === null) {
                $entry['state'] = 'aucune variante accessible';
                $result['items'][] = $entry;
                continue;
            }

            $entry['state']   = 'accessible';
            $entry['quality'] = $quality;
            $entry['signed']  = false;
            $entry['prefix']  = $prefix;
            $entry['uuid']    = $uuid;
            // Playlist absolue pour hls.js dans une data-URI.
            $entry['playlist_abs'] = unlock_absolutize_playlist($base . '_' . $quality . '.m3u8');
            $result['items'][] = $entry;
            continue;
        }

        // Image : la clé directe reste signée sur le CDN, on affiche l'aperçu.
        $entry['state'] = 'image';
        $entry['thumb'] = (string)($media['thumbnail_url'] ?? '');
        $result['items'][] = $entry;
    }

    return $result;
}

/** Découpe une playlist en lignes, et renvoie [prefixe, lignes]. */
function unlock_playlist_lines(string $playlistUrl): array
{
    $response = unlock_http($playlistUrl, ['timeout' => 20]);
    if ($response['status'] !== 200) return [null, []];
    $prefix = '';
    if (preg_match('~^(https://[^/]+/.*/)HLS/~', $playlistUrl, $match)) {
        $prefix = $match[1] . 'HLS/';
    }
    return [$prefix, preg_split('~\r?\n~', trim($response['body']))];
}

/** Playlist avec segments en URL absolues (pour data-URI / hls.js). */
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

/** Playlist avec segments routés par le proxy local (téléchargement ffmpeg). */
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

/** Chemin absolu du répertoire courant, pour les liens de playlist. */
function unlock_script_relative(string $script): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $dir . '/' . $script;
}

/** Vérifie qu'une URL de segment appartient bien au CDN média. */
function unlock_allowed_source(string $url): bool
{
    $host = parse_url($url, PHP_URL_HOST);
    $path = (string)parse_url($url, PHP_URL_PATH);
    return $host === parse_url(UNLOCK_MEDIA, PHP_URL_HOST)
        && strpos($path, '/medias/') === 0
        && !preg_match('~\.\.~', $path);
}
