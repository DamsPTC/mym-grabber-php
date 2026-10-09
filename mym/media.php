<?php
require __DIR__ . '/lib.php';

$user = sanitize_user($_GET['u'] ?? '');
$id   = (string)($_GET['id'] ?? '');
$dl   = isset($_GET['dl']);
if ($user === '' || $id === '') { http_response_code(400); exit('Bad request'); }

// Réutiliser le cache pour ne pas paginer à nouveau pour chaque vignette.
$cached = load_cache($user);
$mediaList = null;
if (!$cached || empty($cached['feed_loaded']) || (time() - $cached['fetched_at']) >= CACHE_TTL) {
    $mediaList = mym_fetch_profile_feed($user, 25, mym_cookie_jar());
}
$prof = get_profile($user, false, $mediaList);
$item = find_media($prof, $id);
if (!$item) { $prof = get_profile($user, true); $item = find_media($prof, $id); }
if (!$item) { http_response_code(404); exit('Media introuvable'); }

// 1er essai, puis nouvelle lecture du profil si l'URL a expiré
$up = http_get($item['url'], 60);
if ($up['code'] !== 200) {
    $prof = get_profile($user, true);
    $item = find_media($prof, $id);
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
