<?php
require __DIR__ . '/lib.php';

$user = sanitize_user($_GET['u'] ?? '');
$id   = (string)($_GET['id'] ?? '');
$dl   = isset($_GET['dl']);
if ($user === '' || $id === '') { http_response_code(400); exit('Bad request'); }

// get_profile() inclut la passe de défloutage continu (retentée à chaque appel).
$prof = get_profile($user);
$item = find_media($prof, $id);
if (!$item) { $prof = get_profile($user, true); $item = find_media($prof, $id); }
if (!$item) { http_response_code(404); exit('Media introuvable'); }

if (mym_media_needs_refresh($item)) {
    $item = mym_refresh_media($user, $item);
    if (!$item) { http_response_code(404); exit('Media indisponible sur le profil'); }
}

$up = null;

// [2] Média encore flouté : tenter la variante nette (cookies CloudFront en
// priorité, puis signature). Si un essai répond 200, on sert ce corps et on
// mémorise l'URL dans le cache pour l'UI et le ZIP.
if (!empty($item['blur']) && mym_deblur_due($item)) {
    $win = mym_try_deblur($item, $user, true);
    if ($win !== null) {
        mym_persist_deblur($user, $item);
        $up = ['code' => 200, 'body' => $win['body']];
    }
}

// URL déjà défloutée via cookies : ceux-ci sont obligatoires pour la servir.
if ($up === null && empty($item['blur']) && !empty($item['cf_auth'])) {
    $t = http_get($item['url'], 60, mym_cf_jar($user));
    if ($t['code'] === 200 && $t['body']) $up = $t;
}

if ($up === null) {
    // Version floutée (signature seule).
    $up = http_get($item['url'], 60);
    if ($up['code'] !== 200) {
        // Signature expirée : retenter avec les cookies seuls (sans signature).
        $jar = mym_cf_jar($user);
        if ($jar !== null) {
            $t = http_get(mym_url_bare($item['url']), 60, $jar);
            if ($t['code'] === 200 && $t['body']) $up = $t;
        }
    }
    if ($up['code'] !== 200) {
        // Nouvelle lecture de la page d'origine, puis nouvelle tentative de
        // défloutage avec l'URL signée fraîche avant de retomber sur le flou.
        $item = mym_refresh_media($user, $item, true);
        if ($item) {
            if (!empty($item['blur']) && mym_deblur_due($item)) {
                $win = mym_try_deblur($item, $user, true);
                if ($win !== null) {
                    mym_persist_deblur($user, $item);
                    $up = ['code' => 200, 'body' => $win['body']];
                }
            }
            if (($up['code'] ?? 0) !== 200) $up = http_get($item['url'], 60);
        }
    }
}

if ($up['code'] !== 200 || !$up['body']) { http_response_code(502); exit('Upstream '.$up['code']); }

// type mime
$ext  = strtolower(pathinfo($item['key'] ?? '', PATHINFO_EXTENSION));
$mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
         'webp'=>'image/webp','mp4'=>'video/mp4','mov'=>'video/quicktime','webm'=>'video/webm',
         'pdf'=>'application/pdf'][$ext] ?? 'application/octet-stream';

$name = basename($item['key'] ?? ('media.' . ($ext ?: 'bin')));
if (!empty($item['deblurred'])) $name = str_replace('-blur', '', $name);

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($up['body']));
// Un média encore flouté peut devenir net : cache court tant qu'il est flouté.
header('Cache-Control: public, max-age=' . (empty($item['blur']) ? 86400 : 300));
header('ETag: "' . md5($id . (empty($item['blur']) ? ':net' : ':blur')) . '"');
if ($dl) header('Content-Disposition: attachment; filename="' . $name . '"');
echo $up['body'];
