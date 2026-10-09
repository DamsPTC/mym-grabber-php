<?php
// lib.php — fonctions partagées
const MYM_HOST   = 'https://mym.fans';
const MEDIA_HOST = 'https://media.prod-v2.mym.fans';
const CACHE_DIR  = __DIR__ . '/cache';
const CACHE_TTL  = 90; // secondes avant de rafraîchir les URL
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

function ensure_cache_dir() { if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true); }

function http_get($url, $timeout = 30) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept-Language: fr,en;q=0.8'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'error' => $err];
}

function sanitize_user($u) {
    $u = trim((string)$u);
    $u = preg_replace('~[^A-Za-z0-9_.\- ]~', '', $u);
    return substr($u, 0, 60);
}

function decode_key($b64) {
    $raw = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    return (is_array($j) && isset($j['key'])) ? $j['key'] : null;
}

function extract_media($html) {
    $html = str_replace(['&amp;', '\\/'], ['&', '/'], $html);
    $out = [];
    $re = '~https://media\.prod-v2\.mym\.fans/medias/v2/([^?"\'\s<>\\\\]+)\?([^"\'\s<>\\\\]+)~';
    if (preg_match_all($re, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $row) {
            $id   = $row[1][0];
            $q    = rtrim($row[2][0], '\\');
            $off  = $row[0][1];
            $key  = decode_key($id);
            $url  = MEDIA_HOST . '/medias/v2/' . $id . '?' . $q;

            // durée vidéo éventuelle : dernier mm:ss avant l'URL
            $dur = '';
            $before = substr($html, max(0, $off - 300), 300);
            if (preg_match('~(\d{1,2}:\d{2})\s*$~', $before, $dm)) $dur = $dm[1];

            $out[$id] = [
                'id'     => $id,
                'url'    => $url,
                'key'    => $key,
                'blur'   => ($key !== null && strpos($key, '-blur') !== false),
                'thumb'  => ($key !== null && strpos($key, '/thumbnails/') !== false),
                'dur'    => $dur,
                'is_vid' => ($key !== null && preg_match('~\.(mp4|mov|webm)$~i', $key)),
            ];
        }
    }
    return array_values($out);
}

function cache_path($user) { return CACHE_DIR . '/' . md5(strtolower($user)) . '.json'; }

function load_cache($user) {
    $p = cache_path($user);
    if (is_file($p)) { $d = json_decode(file_get_contents($p), true); if (is_array($d)) return $d; }
    return null;
}

function get_profile($user, $force = false, ?array $feed = null) {
    if (!$force) {
        $c = load_cache($user);
        if ($c && !empty($c['feed_loaded']) && (time() - $c['fetched_at']) < CACHE_TTL) return $c;
    }
    // Les appels existants avec $force=true rafraîchissent aussi le feed.
    // Une liste fournie par media.php évite un deuxième parcours des pages.
    if ($feed === null) $feed = mym_fetch_profile_feed($user, 25, mym_cookie_jar());
    $r = http_get(MYM_HOST . '/' . rawurlencode($user));
    if (!$feed && $r['code'] === 404) return ['user'=>$user,'notfound'=>true,'media'=>[],'fetched_at'=>time()];
    if (!$feed && ($r['code'] !== 200 || !$r['body']))
        return ['user'=>$user,'error'=>'HTTP '.$r['code'].' '.$r['error'],'media'=>[],'fetched_at'=>time()];

    $html  = $r['code'] === 200 ? (string)$r['body'] : '';
    $media = extract_media($html);
    $byId = [];
    foreach ($media as $item) $byId[$item['id']] = $item;
    foreach ($feed as $item) $byId[$item['id']] = $item;
    $media = array_values($byId);
    $posts = 0;
    if (preg_match('~(\d[\d\s]*)\s*Posts?~i', $html, $mm)) $posts = (int)preg_replace('~\D~', '', $mm[1]);
    $title = $user;
    if (preg_match('~<title>(.*?)</title>~is', $html, $tt)) $title = trim(html_entity_decode($tt[1], ENT_QUOTES));

    $data = ['user'=>$user,'title'=>$title,'posts'=>$posts,'media'=>$media,'fetched_at'=>time(),'feed_loaded'=>true];
    ensure_cache_dir();
    @file_put_contents(cache_path($user), json_encode($data), LOCK_EX);
    return $data;
}

function find_media($prof, $id) {
    foreach ($prof['media'] as $m) if ($m['id'] === $id) return $m;
    return null;
}

/** Client GET HTTPS avec cookies, sans modifier les URL signées. */
function mym_http_get(string $url, array $headers = [], ?string $cookieJar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => UA,
    ]);
    if ($cookieJar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_error($ch);
    if ($cookieJar !== null) curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
    curl_close($ch);
    if ($cookieJar !== null && is_file($cookieJar)) @chmod($cookieJar, 0600);

    $headerSize = (int)($info['header_size'] ?? 0);
    return [
        'status' => $raw === false ? 0 : (int)($info['http_code'] ?? 0),
        'headers' => substr((string)$raw, 0, $headerSize),
        'body' => substr((string)$raw, $headerSize),
        'url' => (string)($info['url'] ?? $url),
        'error' => $error,
    ];
}

/** Connexion facultative ; un simple HTTP 200 ne prouve pas la connexion. */
function mym_login(string $email, string $password, string $cookieJar): bool
{
    $page = mym_http_get(MYM_HOST . '/login', [], $cookieJar);
    $tokenPattern = '~<input\b(?=[^>]*\bname\s*=\s*["\']_token["\'])[^>]*\bvalue\s*=\s*["\']([^"\']+)["\']~i';
    if ($page['status'] !== 200 || !preg_match($tokenPattern, $page['body'], $m)) return false;

    $ch = curl_init(MYM_HOST . '/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        // Ne pas retransmettre les identifiants lors d'une redirection POST.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'email' => $email,
            'password' => $password,
            '_token' => html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'),
        ]),
        CURLOPT_USERAGENT => UA,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST);
    curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
    curl_close($ch);
    if (is_file($cookieJar)) @chmod($cookieJar, 0600);
    if ($body === false || !in_array($code, [200, 204, 302, 303], true) || !$cookies) return false;

    // Vérifier que la session quitte réellement la page de connexion.
    $check = mym_http_get(MYM_HOST . '/login', [], $cookieJar);
    $path = (string)parse_url($check['url'], PHP_URL_PATH);
    return $check['status'] === 200
        && parse_url($check['url'], PHP_URL_HOST) === parse_url(MYM_HOST, PHP_URL_HOST)
        && !preg_match('~/(?:login|signin|2fa|two-factor|challenge|verify)(?:/|$)~i', $path)
        && !preg_match('~<input\b[^>]*\btype\s*=\s*["\']password["\']~i', $check['body']);
}

/**
 * Session unique du compte fan, conservée sous le cache protégé par .htaccess.
 * Configurer MYM_EMAIL et MYM_PASSWORD côté serveur ; aucun secret dans Git.
 * Sans ces variables ni jar existant, le feed est interrogé sans connexion.
 */
function mym_cookie_jar(): ?string
{
    static $resolved = false;
    static $jar = null;
    if ($resolved) return $jar;
    $resolved = true;
    $path = CACHE_DIR . '/cookies/mym_fan.jar';
    if (is_file($path) && filesize($path) > 0) return $jar = $path;

    $email = (string)getenv('MYM_EMAIL');
    $password = (string)getenv('MYM_PASSWORD');
    if ($email === '' || $password === '') return null;
    ensure_cache_dir();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return null;
    @chmod($directory, 0700);
    if (!mym_login($email, $password, $path)) return null;
    return $jar = $path;
}

/** Extrait HTML/JSON et conserve le format attendu par find_media(). */
function mym_parse_media_from_feed(string $body): array
{
    $json = json_decode($body, true);
    $fragments = [];
    if (is_array($json)) {
        array_walk_recursive($json, static function ($value) use (&$fragments) {
            if (is_string($value)) $fragments[] = $value;
        });
    } else {
        $fragments[] = is_string($json) ? $json : $body;
    }

    $out = [];
    foreach ($fragments as $fragment) {
        $fragment = html_entity_decode(str_replace('\\/', '/', $fragment), ENT_QUOTES, 'UTF-8');
        if (!preg_match_all('~https://media\.prod-v2\.mym\.fans/[^\s"\'<>\\\\]+~', $fragment, $matches)) continue;
        foreach ($matches[0] as $url) {
            $path = (string)parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/medias/v2/([^/]+)$~', $path, $idMatch)) {
                $id = $idMatch[1];
                $decodedKey = decode_key($id);
                $key = is_string($decodedKey) ? $decodedKey : null;
            } else {
                // Identifiant stable : les paramètres de signature sont exclus.
                $id = 'feed_' . hash('sha256', $path);
                $key = ltrim(rawurldecode($path), '/');
            }
            $filename = basename($key ?? rawurldecode($path));
            $out[$id] = [
                'id' => $id,
                'url' => $url,
                'key' => $key,
                // Le nom peut être encodé dans le payload base64 du chemin v2.
                'blur' => strpos($filename, '-blur') !== false,
                'thumb' => strpos('/' . ltrim($key ?? $path, '/'), '/thumbnails/') !== false,
                'dur' => '',
                'is_vid' => (bool)preg_match('~\.(mp4|mov|webm)$~i', $filename),
                'media_id' => $id,
            ];
        }
    }
    return array_values($out);
}

/**
 * Parcourt les pages accessibles à la session, dans la limite de $maxPages.
 * Les URL sont utilisées telles que reçues ; aucune transformation du flou.
 */
function mym_fetch_profile_feed(string $creator, int $maxPages = 25, ?string $cookieJar = null): array
{
    $base = MYM_HOST . '/app/ajax/profile/' . rawurlencode($creator) . '/feed';
    $headers = [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Referer: ' . MYM_HOST . '/' . rawurlencode($creator),
    ];
    $items = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $url = $base . '?page=' . $page . '&sort=date_desc&media_type=all';
        $response = mym_http_get($url, $headers, $cookieJar);
        if ($response['status'] !== 200
            || parse_url($response['url'], PHP_URL_HOST) !== parse_url(MYM_HOST, PHP_URL_HOST)
            || parse_url($response['url'], PHP_URL_PATH) !== parse_url($base, PHP_URL_PATH)) break;
        $found = mym_parse_media_from_feed($response['body']);
        if (!$found) break;
        $added = 0;
        foreach ($found as $item) {
            if (!isset($items[$item['id']])) $added++;
            $items[$item['id']] = $item;
        }
        // Certains endpoints répètent la dernière page au lieu d'en renvoyer une vide.
        if ($added === 0) break;
    }
    return array_values($items);
}
