<?php
require __DIR__ . '/lib.php';
set_time_limit(0);

$user = sanitize_user($_GET['u'] ?? '');
if ($user === '') exit('user manquant');

$prof = get_profile($user, true);
$tmp  = tempnam(sys_get_temp_dir(), 'mym');
$zip  = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) exit('zip init failed');

$n = 0;
foreach ($prof['media'] as $m) {
    if ($m['thumb']) continue;          // on saute les miniatures
    if ($n >= 120) break;

    $r = null;

    // [2] Défloutage best-effort au moment du téléchargement : la variante
    // nette (même signature) est essayée en premier pour chaque média flouté.
    if (!empty($m['blur'])) {
        $cand = deblur_url($m['url']);
        if ($cand !== null) {
            $r = http_get($cand, 60);
            if ($r['code'] === 200 && $r['body']) {
                $m['url'] = $cand;
                $m['blur'] = false;
                $m['deblurred'] = true;
                mym_persist_deblur($user, $m);
            } else {
                $r = null;
            }
        }
    }

    if ($r === null) {
        if (mym_media_needs_refresh($m)) {
            $m = mym_refresh_media($user, $m);
            if (!$m) continue;
        }
        $r = http_get($m['url'], 60);
        if ($r['code'] !== 200) {
            $m = mym_refresh_media($user, $m, true);
            if (!$m) continue;
            $r = http_get($m['url'], 60);
        }
    }

    if ($r['code'] === 200 && $r['body']) {
        $name = basename($m['key'] ?? ('m' . $n));
        if (!empty($m['deblurred'])) $name = str_replace('-blur', '', $name);
        $zip->addFromString($name, $r['body']);
        $n++;
    }
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="mym_' . $user . '.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
