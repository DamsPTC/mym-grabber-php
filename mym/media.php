<?php
require __DIR__ . '/lib.php';

$user = sanitize_user($_GET['u'] ?? '');
$id   = (string)($_GET['id'] ?? '');
$dl   = isset($_GET['dl']);
if ($user === '' || $id === '') { http_response_code(400); exit('Bad request'); }

// Un ancien catalogue conserve la page d'origine ; seules ses URL sont rafraîchies.
$prof = load_cache($user);
if (!$prof || ($prof['feed_version'] ?? 0) !== FEED_CACHE_VERSION) $prof = get_profile($user);
$item = find_media($prof, $id);
if (!$item) { $prof = get_profile($user, true); $item = find_media($prof, $id); }
if (!$item) { http_response_code(404); exit('Media introuvable'); }

if (mym_media_needs_refresh($item)) {
    $item = mym_refresh_media($user, $item);
    if (!$item) { http_response_code(404); exit('Media indisponible sur le profil'); }
}

// 1er essai, puis nouvelle lecture de la page d'origine si l'URL a expiré.
$up = http_get($item['url'], 60);
if ($up['code'] !== 200) {
    $item = mym_refresh_media($user, $item, true);
    if ($item) $up = http_get($item['url'], 60);
}
if ($up['code'] !== 200 || !$up['body']) { http_response_code(502); exit('Upstream '.$up['code']); }

// type mime
$ext  = strtolower(pathinfo($item['key'] ?? '', PATHINFO_EXTENSION));
$mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
         'webp'=>'image/webp','mp4'=>'video/mp4','mov'=>'video/quicktime','webm'=>'video/webm',
         'pdf'=>'application/pdf'][$ext] ?? 'application/octet-stream';

$name = basename($item['key'] ?? ('media.' . ($ext ?: 'bin')));

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($up['body']));
header('Cache-Control: public, max-age=86400');
header('ETag: "' . md5($id) . '"');
if ($dl) header('Content-Disposition: attachment; filename="' . $name . '"');
echo $up['body'];
