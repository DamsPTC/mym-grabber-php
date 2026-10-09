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

    // [2] Défloutage au téléchargement : cookies CloudFront en priorité.
    if (!empty($m['blur']) && mym_deblur_due($m)) {
        $win = mym_try_deblur($m, $user, true);
        if ($win !== null) {
            mym_persist_deblur($user, $m);
            $r = ['code' => 200, 'body' => $win['body']];
        }
    }

    // URL déjà défloutée via cookies : les joindre au téléchargement.
    if ($r === null && empty($m['blur']) && !empty($m['cf_auth'])) {
        $t = http_get($m['url'], 60, mym_cf_jar($user));
        if ($t['code'] === 200 && $t['body']) $r = $t;
    }

    if ($r === null) {
        if (mym_media_needs_refresh($m)) {
            $m = mym_refresh_media($user, $m);
            if (!$m) continue;
            if (!empty($m['blur']) && mym_deblur_due($m)) {
                $win = mym_try_deblur($m, $user, true);
                if ($win !== null) {
                    mym_persist_deblur($user, $m);
                    $r = ['code' => 200, 'body' => $win['body']];
                }
            }
        }
        if ($r === null) {
            $r = http_get($m['url'], 60);
            if ($r['code'] !== 200) {
                // Signature expirée : retenter avec les cookies seuls.
                $jar = mym_cf_jar($user);
                if ($jar !== null) {
                    $t = http_get(mym_url_bare($m['url']), 60, $jar);
                    if ($t['code'] === 200 && $t['body']) $r = $t;
                }
            }
            if ($r['code'] !== 200) {
                $m2 = mym_refresh_media($user, $m, true);
                if (!$m2) continue;
                $m = $m2;
                $r = http_get($m['url'], 60);
            }
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
