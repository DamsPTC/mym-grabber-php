<?php
// unlock/etat.php — état de la session fan + dépôt manuel de secours
require __DIR__ . '/lib.php';
require __DIR__ . '/session.php';

$report = unlock_session_report();
$token = trim((string)@file_get_contents(UNLOCK_STATE_DIR . '/refresh.token'));
$posted = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $_POST['key'] = (string)($_POST['key'] ?? '');
    ob_start();
    require __DIR__ . '/session-set.php';
    $posted = ob_get_clean();
}

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Session — MYM Unlock</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 2rem 1rem; font: 15px/1.5 system-ui, sans-serif; background: #0d0f14; color: #e7e9ee; }
  .wrap { max-width: 760px; margin: 0 auto; }
  h1 { font-size: 1.25rem; margin: 0 0 1rem; }
  h2 { font-size: .95rem; margin: 1.6rem 0 .5rem; color: #b9c2d4; }
  .card { border: 1px solid #262b36; border-radius: 10px; padding: 1rem; background: #12151b; margin-bottom: 1rem; }
  .row { display: flex; justify-content: space-between; gap: 1rem; padding: .3rem 0; font-size: .86rem; border-bottom: 1px solid #1a1f28; }
  .row:last-child { border-bottom: 0; }
  .row span:first-child { color: #8b93a7; }
  code { font-size: .78rem; word-break: break-all; color: #9aa4bb; }
  textarea { width: 100%; min-height: 5rem; padding: .6rem; border-radius: 8px; border: 1px solid #262b36; background: #14171e; color: inherit; font: .78rem ui-monospace, monospace; }
  button { margin-top: .6rem; padding: .55rem 1rem; border-radius: 8px; border: 0; background: #4c7dff; color: #fff; font-weight: 600; cursor: pointer; }
  .ok { color: #74e2a0; } .bad { color: #ff9a9a; } .warn { color: #ffb27a; }
  a { color: #7aa2ff; }
  pre { background: #0f1218; border: 1px solid #1d222c; border-radius: 8px; padding: .7rem; font-size: .75rem; overflow: auto; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Session fan</h1>
  <p><a href="../unlock.php">← retour</a></p>

  <div class="card">
    <div class="row"><span>état</span><span class="<?= $report['state'] === 'connected' ? 'ok' : ($report['state'] === 'expired' ? 'warn' : 'bad') ?>"><?= h($report['state']) ?></span></div>
    <div class="row"><span>source</span><span><?= h($report['source'] ?: '—') ?></span></div>
    <div class="row"><span>compte</span><span><?= h($report['account'] ?: '—') ?></span></div>
    <div class="row"><span>expire</span><span><?= $report['expires_at'] ? h(gmdate('c', (int)$report['expires_at'])) : '—' ?></span></div>
    <div class="row"><span>dernier événement</span><span><code><?= h($report['last_event'] ?: '—') ?></code></span></div>
  </div>

  <h2>Rafraîchissement automatique</h2>
  <div class="card">
    <p style="font-size:.84rem;color:#8b93a7;margin:0 0 .5rem">À appeler par cron toutes les 15 minutes :</p>
    <pre>curl -s "https://<?= h($_SERVER['HTTP_HOST'] ?? 'votre-domaine') ?><?= h(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))) ?>/refresh.php?key=<?= h($token ?: 'TOKEN_ABSENT') ?>"</pre>
    <p style="font-size:.82rem;color:#8b93a7;margin:.6rem 0 0">Le jeton est créé au premier appel de <code>refresh.php</code>. Si le compte est fédéré Google, le login par mot de passe échoue : utiliser le dépôt manuel ci-dessous.</p>
  </div>

  <h2>Secours — coller un cookie de session</h2>
  <div class="card">
    <form method="post" action="etat.php">
      <input type="hidden" name="key" value="<?= h($token) ?>">
      <p style="font-size:.82rem;color:#8b93a7;margin:0 0 .5rem">Sur mym.fans connecté : DevTools → Réseau → une requête vers <code>styx.mym.fans</code> → en-tête <code>Cookie</code>. Coller la valeur entière.</p>
      <textarea name="cookie" placeholder="a=1; b=2; ..."></textarea>
      <button type="submit">Valider le cookie</button>
    </form>
    <?php if ($posted !== null): ?>
      <pre><?= h($posted) ?></pre>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
