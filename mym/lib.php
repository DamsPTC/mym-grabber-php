<?php
// lib.php — fonctions partagées
const MYM_HOST   = 'https://mym.fans';
const MEDIA_HOST = 'https://media.prod-v2.mym.fans';
const CACHE_DIR  = __DIR__ . '/cache';
const CACHE_TTL  = 90; // secondes avant de rafraîchir les URL
// v4 : caches v3 sans cf_auth (cookies CloudFront du layer défloutage).
const FEED_CACHE_VERSION = 4;
// Défloutage : délai minimum entre deux tentatives sur un même média flouté,
// et nombre maximum de tentatives par passage de get_profile().
const DEBLUR_RETRY_DELAY = 45;
const DEBLUR_MAX_PER_PASS = 10;
// Cookies CloudFront : rafraîchis au maximum toutes les 30 minutes.
const CF_COOKIE_TTL = 1800;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

function ensure_cache_dir() { if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true); }

function http_get($url, $timeout = 30, $jar = null) {
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
    if ($jar !== null) {
        // Cookies CloudFront éventuels (policy wildcard) pour l'accès média.
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
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
    $data = null;
    if (!$force) {
        $cached = load_cache($user);
        if ($cached && ($cached['feed_version'] ?? 0) === FEED_CACHE_VERSION
            && (time() - $cached['fetched_at']) < CACHE_TTL) $data = $cached;
    }
    if ($data === null) {
        $jar = mym_cookie_jar();
        if ($feed === null) $feed = mym_fetch_profile_feed($user, 25, $jar, $force);
        $first = mym_fetch_profile_page($user, 1, $jar);
        if (!$feed && $first['status'] === 404) return ['user'=>$user,'notfound'=>true,'media'=>[],'fetched_at'=>time()];
        if (!$feed && $first['status'] !== 200)
            return ['user'=>$user,'error'=>'HTTP '.$first['status'],'media'=>[],'fetched_at'=>time()];

        $data = ['user'=>$user,'title'=>$first['title'] ?? $user,'posts'=>$first['posts'] ?? 0,
            'media'=>$feed,'fetched_at'=>time(),'feed_loaded'=>true,'feed_version'=>FEED_CACHE_VERSION];
    }

    // [2] Défloutage best-effort CONTINU, cookies CloudFront en priorité :
    // retenté à chaque appel tant que le média reste flouté (throttle par média
    // via deblur_tried_at, quota par passage avec rotation des moins récemment
    // tentés). Un succès remplace l'URL et est mémorisé dans le cache.
    $due = [];
    foreach ($data['media'] as $k => $x) {
        if (mym_deblur_due($x)) $due[$k] = (int)($x['deblur_tried_at'] ?? 0);
    }
    if ($due) {
        asort($due);
        foreach (array_slice($due, 0, DEBLUR_MAX_PER_PASS, true) as $k => $t) {
            mym_try_deblur($data['media'][$k], $user);
        }
        ensure_cache_dir();
        @file_put_contents(cache_path($user), json_encode($data), LOCK_EX);
    }
    return $data;
}

function find_media($prof, $id) {
    foreach ($prof['media'] as $m) if ($m['id'] === $id) return $m;
    // Un descripteur CDN peut changer ; retrouver uniquement une clé déjà exposée.
    $key = decode_key($id);
    if (is_string($key)) {
        foreach ($prof['media'] as $m) if (($m['key'] ?? null) === $key) return $m;
    }
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

// ---- Cookies CloudFront signés (vecteur du défloutage) ----

/** URL sans la query de signature (accès par cookies uniquement). */
function mym_url_bare(string $url): string
{
    $cut = strpos($url, '?');
    return $cut === false ? $url : substr($url, 0, $cut);
}

function mym_cf_jar_path(): string { return CACHE_DIR . '/cookies/cf.jar'; }

/** Le jar contient-il une policy CloudFront signée ? */
function mym_jar_has_cloudfront_policy(string $jar): bool
{
    $lines = @file($jar);
    if (!is_array($lines)) return false;
    foreach ($lines as $line) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || $line[0] === '#') continue;
        $parts = preg_split('~\t~', $line);
        if (count($parts) >= 7 && $parts[5] === 'CloudFront-Policy') return true;
    }
    return false;
}

/** Visite les pages publiques pour collecter/rafraîchir les cookies CloudFront. */
function mym_cf_refresh_cookies(?string $creator = null): void
{
    $path = mym_cf_jar_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
    @chmod($directory, 0700);
    $targets = [MYM_HOST . '/'];
    if ($creator !== null && $creator !== '') $targets[] = MYM_HOST . '/' . rawurlencode($creator);
    foreach ($targets as $url) mym_http_get($url, ['Accept: text/html'], $path);
    if (is_file($path)) @chmod($path, 0600);
}

/**
 * Jar de cookies CloudFront à joindre aux requêtes média.
 * Priorité : session fan si elle porte déjà la policy (accès le plus large),
 * sinon jar anonyme rafraîchi toutes les CF_COOKIE_TTL secondes en visitant
 * la page du profil et la page d'accueil. Null si aucune policy capturée.
 */
function mym_cf_jar(?string $creator = null): ?string
{
    static $resolved = false;
    static $jar = null;
    if ($resolved) return $jar;
    $resolved = true;

    $fan = mym_cookie_jar();
    if (is_string($fan) && mym_jar_has_cloudfront_policy($fan)) return $jar = $fan;

    $path = mym_cf_jar_path();
    if (!is_file($path) || filesize($path) === 0
        || (time() - (int)@filemtime($path)) > CF_COOKIE_TTL
        || !mym_jar_has_cloudfront_policy($path)) {
        mym_cf_refresh_cookies($creator);
    }
    if (is_file($path) && mym_jar_has_cloudfront_policy($path)) return $jar = $path;
    return $jar = null;
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
 * Parcourt les pages HTML du profil, dans la limite de $maxPages.
 * Les URL sont utilisées telles que reçues ; aucune transformation du flou.
 */
function mym_fetch_profile_feed(string $creator, int $maxPages = 25, ?string $cookieJar = null, bool $force = false): array
{
    $items = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $response = mym_fetch_profile_page($creator, $page, $cookieJar, $force);
        if ($response['status'] !== 200) break;
        $found = $response['media'];
        if (!$found) break;
        $added = 0;
        foreach ($found as $item) {
            $identity = mym_media_identity($item);
            if (!isset($items[$identity])) { $items[$identity] = $item; $added++; }
        }
        // Certains endpoints répètent la dernière page au lieu d'en renvoyer une vide.
        if ($added === 0) break;
    }
    return array_values($items);
}

function mym_media_identity(array $item): string
{
    return is_string($item['key'] ?? null) ? 'key:' . $item['key'] : 'id:' . $item['id'];
}

// [2] Layer défloutage continu (best-effort), via cookies CloudFront.

function mym_b64d($s) { return base64_decode(strtr($s, '-_', '+/')); }
function mym_b64e($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/**
 * Variante non floutée d'une URL média, en conservant la même signature :
 * - /medias/v2/{b64url} : retire "-blur" du descripteur DÉCODÉ puis ré-encode,
 *   sans passer par json_decode/json_encode (préservation octet par octet
 *   du reste du descripteur, pour ne pas casser ce que couvre la signature).
 * - autres chemins du CDN : retire "-blur" directement du chemin.
 * Retourne null si l'URL n'est pas floutée ou hors du CDN média.
 */
function deblur_url(string $url): ?string
{
    if (strpos($url, MEDIA_HOST . '/') !== 0) return null;
    $cut   = strpos($url, '?');
    $base  = ($cut === false) ? $url : substr($url, 0, $cut);
    $query = ($cut === false) ? '' : substr($url, $cut);

    if (preg_match('~^' . preg_quote(MEDIA_HOST, '~') . '/medias/v2/([^/]+)$~', $base, $m)) {
        $raw = mym_b64d($m[1]);
        if ($raw === false || $raw === '' || strpos($raw, '-blur') === false) return null;
        return MEDIA_HOST . '/medias/v2/' . mym_b64e(str_replace('-blur', '', $raw)) . $query;
    }

    if (strpos($base, '-blur') === false) return null;
    return str_replace('-blur', '', $base) . $query;
}

/** Vérifie qu'une URL est servie (HTTP 200/206) en évitant de télécharger le corps. */
function mym_url_reachable(string $url, ?string $jar = null): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => UA,
    ]);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) return true;
    // Certains CDN refusent HEAD : confirmer avec un GET allégé (Range).
    $r = mym_http_get($url, ['Range: bytes=0-0'], $jar);
    return $r['status'] === 200 || $r['status'] === 206;
}

/** Un média flouté mérite-t-il une (nouvelle) tentative de défloutage ? */
function mym_deblur_due(array $item): bool
{
    if (empty($item['blur'])) return false;
    return (time() - (int)($item['deblur_tried_at'] ?? 0)) >= DEBLUR_RETRY_DELAY;
}

/**
 * Essaie la variante nette d'un média flouté, dans l'ordre :
 *  1. URL nette SANS signature + cookies CloudFront (policy wildcard) ;
 *  2. URL nette AVEC signature d'origine + cookies CloudFront ;
 *  3. URL nette AVEC signature seule (historique).
 * Retourne ['url', 'cf_auth', 'body'] au premier essai qui répond 200
 * (corps rempli si $withBody), sinon null.
 */
function mym_deblur_attempt(string $creator, array $item, bool $withBody = false): ?array
{
    $candidate = deblur_url($item['url']);
    if ($candidate === null) return null;

    $jar = mym_cf_jar($creator);
    $attempts = [];
    if ($jar !== null) {
        $attempts[] = [mym_url_bare($candidate), $jar];
        $attempts[] = [$candidate, $jar];
    }
    $attempts[] = [$candidate, null];

    foreach ($attempts as [$url, $cookieJar]) {
        if ($withBody) {
            $r = http_get($url, 60, $cookieJar);
            if ($r['code'] === 200 && $r['body'] !== '') {
                return ['url' => $url, 'cf_auth' => ($cookieJar !== null), 'body' => $r['body']];
            }
        } elseif (mym_url_reachable($url, $cookieJar)) {
            return ['url' => $url, 'cf_auth' => ($cookieJar !== null), 'body' => null];
        }
    }
    return null;
}

/**
 * Tente la variante nette d'un média flouté ; marque la tentative dans l'item.
 * Retourne le résultat de mym_deblur_attempt (null = encore flouté).
 */
function mym_try_deblur(array &$item, string $creator, bool $withBody = false): ?array
{
    $item['deblur_tried_at'] = time();
    $win = mym_deblur_attempt($creator, $item, $withBody);
    if ($win === null) return null;
    $item['url'] = $win['url'];
    $item['blur'] = false;
    $item['deblurred'] = true;
    $item['cf_auth'] = $win['cf_auth'];
    $item['url_fetched_at'] = time();
    $item['expires_at'] = mym_signed_url_expiry($win['url']);
    return $win;
}

/** Mémorise un défloutage réussi dans le cache du profil, si présent. */
function mym_persist_deblur(string $user, array $item): void
{
    $prof = load_cache($user);
    if (!is_array($prof) || !is_array($prof['media'] ?? null)) return;
    foreach ($prof['media'] as &$m) {
        if (($m['id'] ?? null) === $item['id']) {
            $m['url'] = $item['url'];
            $m['blur'] = false;
            $m['deblurred'] = true;
            $m['cf_auth'] = !empty($item['cf_auth']);
            $m['deblur_tried_at'] = time();
            $m['url_fetched_at'] = time();
            $m['expires_at'] = mym_signed_url_expiry($item['url']);
            break;
        }
    }
    unset($m);
    @file_put_contents(cache_path($user), json_encode($prof), LOCK_EX);
}

/** Lit l'expiration annoncée, sans fabriquer ni modifier la signature. */
function mym_signed_url_expiry(string $url): ?int
{
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    if (isset($query['Expires']) && is_scalar($query['Expires']) && ctype_digit((string)$query['Expires'])) {
        return (int)$query['Expires'];
    }
    if (!isset($query['Policy']) || !is_string($query['Policy'])) return null;
    $raw = base64_decode(strtr($query['Policy'], ['-'=>'+', '_'=>'=', '~'=>'/']), true);
    if ($raw === false) return null;
    $policy = json_decode($raw, true);
    $times = [];
    foreach (($policy['Statement'] ?? []) as $statement) {
        $expiry = $statement['Condition']['DateLessThan']['AWS:EpochTime'] ?? null;
        if (is_numeric($expiry)) $times[] = (int)$expiry;
    }
    return $times ? min($times) : null;
}

function mym_media_needs_refresh(array $item): bool
{
    $expiry = $item['expires_at'] ?? mym_signed_url_expiry($item['url']);
    return ($expiry !== null && $expiry <= time() + 10)
        || time() - ($item['url_fetched_at'] ?? 0) >= CACHE_TTL;
}

/** Lecture d'attributs HTML sans dépendance à l'extension DOM. */
function mym_html_attributes(string $tag): array
{
    $attributes = [];
    preg_match_all('~([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~s', $tag, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $value = $match[2] !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
        $attributes[strtolower($match[1])] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }
    return $attributes;
}

/** Uniquement les médias de la grille : exclut avatar, couverture et scripts. */
function mym_parse_profile_media(string $html, int $page): array
{
    $html = preg_replace('~<!--.*?-->|<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $html);
    preg_match_all('~</?(?:div|img|video|source)\b[^>]*>~i', $html, $matches);
    $depth = 0;
    $grids = [];
    $sources = [];
    foreach ($matches[0] as $tag) {
        if (preg_match('~^</div\b~i', $tag)) {
            if ($grids && end($grids) === $depth) array_pop($grids);
            $depth = max(0, $depth - 1);
            continue;
        }
        $attributes = mym_html_attributes($tag);
        $classes = ' ' . preg_replace('~\s+~', ' ', $attributes['class'] ?? '') . ' ';
        if (preg_match('~^<div\b~i', $tag)) {
            $depth++;
            if (strpos($classes, ' medias-grid ') !== false) $grids[] = $depth;
            continue;
        }
        if (!$grids) continue;
        if (preg_match('~^<img\b~i', $tag) && strpos($classes, ' media__image ') === false) continue;
        foreach (['data-src', 'src'] as $attribute) {
            $url = $attributes[$attribute] ?? '';
            if (strpos($url, MEDIA_HOST . '/') === 0) { $sources[] = $url; break; }
        }
    }
    $items = [];
    foreach (mym_parse_media_from_feed(implode("\n", $sources)) as $item) {
        $item['source_page'] = $page;
        $item['url_fetched_at'] = time();
        $item['expires_at'] = mym_signed_url_expiry($item['url']);
        $items[mym_media_identity($item)] = $item;
    }
    return array_values($items);
}

/** Cache court par page pour partager les nouvelles URL entre les téléchargements. */
function mym_fetch_profile_page(string $creator, int $page, ?string $cookieJar = null, bool $force = false): array
{
    $page = max(1, $page);
    $directory = CACHE_DIR . '/pages';
    $path = $directory . '/' . md5(strtolower($creator)) . '-' . $page . '.json';
    if (!$force && is_file($path)) {
        $cached = json_decode((string)file_get_contents($path), true);
        if (is_array($cached) && ($cached['feed_version'] ?? 0) === FEED_CACHE_VERSION
            && time() - ($cached['fetched_at'] ?? 0) < min(CACHE_TTL, 30)) {
            $fresh = true;
            foreach ($cached['media'] as $item) if (mym_media_needs_refresh($item)) { $fresh = false; break; }
            if ($fresh) return $cached;
        }
    }
    $base = MYM_HOST . '/' . rawurlencode($creator);
    $response = mym_http_get($base . '?page=' . $page, ['Accept: text/html', 'Referer: ' . $base], $cookieJar);
    $status = $response['status'];
    if ($status === 200 && (parse_url($response['url'], PHP_URL_HOST) !== parse_url(MYM_HOST, PHP_URL_HOST)
        || rtrim((string)parse_url($response['url'], PHP_URL_PATH), '/') !== '/' . rawurlencode($creator))) $status = 403;
    $data = ['status'=>$status,'user'=>$creator,'page'=>$page,'media'=>[],
        'title'=>$creator,'posts'=>0,'fetched_at'=>time(),'feed_version'=>FEED_CACHE_VERSION];
    if ($status !== 200) return $data;
    $data['media'] = mym_parse_profile_media($response['body'], $page);
    if (preg_match('~<title>(.*?)</title>~is', $response['body'], $title))
        $data['title'] = trim(html_entity_decode($title[1], ENT_QUOTES, 'UTF-8'));
    $text = html_entity_decode(strip_tags($response['body']), ENT_QUOTES, 'UTF-8');
    if (preg_match('~(\d[\d\s\x{00a0}\x{202f}]*)\s*(?:Posts?|Publications?)\b~iu', $text, $posts))
        $data['posts'] = (int)preg_replace('~\D~', '', $posts[1]);
    ensure_cache_dir();
    if (is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory))
        @file_put_contents($path, json_encode($data), LOCK_EX);
    return $data;
}

/** Relire d'abord la page d'origine ; parcourir le profil seulement si le média a changé de page. */
function mym_refresh_media(string $creator, array $item, bool $force = false): ?array
{
    $page = (int)($item['source_page'] ?? 0);
    if ($page > 0) {
        $result = mym_fetch_profile_page($creator, $page, mym_cookie_jar(), $force);
        if ($result['status'] !== 200) return null;
        $identity = mym_media_identity($item);
        foreach ($result['media'] as $candidate) {
            if (mym_media_identity($candidate) === $identity) return $candidate;
        }
    }
    $profile = get_profile($creator, true);
    return find_media($profile, $item['id']);
}
