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

function get_profile($user, $force = false) {
    if (!$force) {
        $c = load_cache($user);
        if ($c && (time() - $c['fetched_at']) < CACHE_TTL) return $c;
    }
    $r = http_get(MYM_HOST . '/' . rawurlencode($user));
    if ($r['code'] === 404) return ['user'=>$user,'notfound'=>true,'media'=>[],'fetched_at'=>time()];
    if ($r['code'] !== 200 || !$r['body'])
        return ['user'=>$user,'error'=>'HTTP '.$r['code'].' '.$r['error'],'media'=>[],'fetched_at'=>time()];

    $html  = $r['body'];
    $media = extract_media($html);
    $posts = 0;
    if (preg_match('~(\d[\d\s]*)\s*Posts?~i', $html, $mm)) $posts = (int)preg_replace('~\D~', '', $mm[1]);
    $title = $user;
    if (preg_match('~<title>(.*?)</title>~is', $html, $tt)) $title = trim(html_entity_decode($tt[1], ENT_QUOTES));

    $data = ['user'=>$user,'title'=>$title,'posts'=>$posts,'media'=>$media,'fetched_at'=>time()];
    ensure_cache_dir();
    @file_put_contents(cache_path($user), json_encode($data));
    return $data;
}

function find_media($prof, $id) {
    foreach ($prof['media'] as $m) if ($m['id'] === $id) return $m;
    return null;
}
