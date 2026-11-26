<?php
// unlock/debug.php — diagnostic de la structure et du décodage
require __DIR__ . '/lib.php';

$username = isset($_GET['u']) ? unlock_sanitize_user((string)$_GET['u']) : 'Maximeyedess';
$postId   = isset($_GET['p']) ? trim((string)$_GET['p']) : '395f38c4-6adf-4f67-a5bf-2c1c349a2906';

header('Content-Type: application/json; charset=utf-8');

$fetched = unlock_fetch_post($username, $postId);
if (isset($fetched['error'])) { echo json_encode(['error' => $fetched['error']]); exit; }

$post = $fetched['post'];
$out = [
    'postKeys' => array_keys($post),
    'gate' => $post['gate'] ?? null,
    'nGroups' => count($post['medias'] ?? []),
    'groupShapes' => [],
    'collected' => [],
];

foreach (($post['medias'] ?? []) as $i => $group) {
    $shape = array_keys($group);
    $out['groupShapes'][] = $shape;
    $out['teaser' . $i] = isset($group['teaser']) ? array_keys($group['teaser']) : null;
    $out['teaserThumb' . $i] = isset($group['teaser']['thumbnail_url']) ? substr($group['teaser']['thumbnail_url'], 0, 50) : null;
}

// ce que le collecteur retient réellement
foreach (unlock_collect_media($post) as $idx => $media) {
    $thumb = (string)($media['thumbnail_url'] ?? '');
    $prefix = unlock_media_prefix($media);
    $entry = [
        'keys' => array_keys($media),
        'mime' => $media['mime_type'] ?? null,
        'thumbLen' => strlen($thumb),
        'prefix' => $prefix,
    ];
    if ($thumb !== '' && preg_match('~/medias/v2/([^?]+)~', $thumb, $m)) {
        $desc = unlock_decode_descriptor($m[1]);
        $entry['key'] = is_array($desc) ? ($desc['key'] ?? null) : 'DECODE_FAIL';
    }
    $out['collected'][] = $entry;
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
