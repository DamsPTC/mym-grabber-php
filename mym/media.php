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

// [2] Média encore flouté : tenter d'abord la variante nette (même signature).
// Si elle répond 200, on la sert et on mémorise le succès dans le cache
// pour que l'UI et le ZIP utilisent directement l'URL nette ensuite.
if (!empty($item['blur'])) {
    $cand = deblur_url($item['url']);
    if ($cand !== null) {
        $try = http_get($cand, 60);
        if ($try['code'] === 200 && $try['body']) {
            $item['url'] = $cand;
            $item['blur'] = false;
            $item['deblurred'] = true;
            mym_persist_deblur($user, $item);
            $up = $try;
        }
    }
}

// Sinon : version floutée, puis nouvelle lecture de la page d'origine si l'URL a expiré.
if ($up === null) {
    $up = http_get($item['url'], 60);
    if ($up['code'] !== 200) {
        $item = mym_refresh_media($user, $item, true);
        if ($item) $up = http_get($item['url'], 60);
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
