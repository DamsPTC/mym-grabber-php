<?php
// unlock/index.php — interface de déverrouillage
require __DIR__ . '/lib.php';
require __DIR__ . '/session.php';

/** Accepte un UUID nu ou une URL MYM (?media=UUID ou /post/UUID). */
function unlock_parse_post(string $raw): string
{
    $raw = trim($raw);
    if (unlock_is_uuid($raw)) return $raw;
    if (preg_match('~[?&]media=([0-9a-f-]{36})~i', $raw, $m)) return $m[1];
    if (preg_match('~/posts?/([0-9a-f-]{36})~i', $raw, $m)) return $m[1];
    return '';
}

$username = isset($_GET['u']) ? unlock_sanitize_user((string)$_GET['u']) : '';
$rawInput = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
$postId   = unlock_parse_post($rawInput);
$result   = null;
$posts    = null;

if ($username === '' && $postId !== '' && preg_match('~mym\.fans/([A-Za-z0-9_.\-]+)~i', $rawInput, $m)) {
    $username = unlock_sanitize_user($m[1]);
}

$session = unlock_session_report();

if ($username !== '' && $postId !== '') {
    $result = unlock_resolve($username, $postId);
} elseif ($username !== '' && $rawInput === '') {
    $posts = unlock_list_posts($username);
} elseif ($rawInput !== '' && $postId === '') {
    $result = ['error' => 'UUID de post introuvable dans la saisie'];
}

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function unlock_ago(?int $timestamp): string
{
    if (!$timestamp) return '—';
    $delta = time() - $timestamp;
    if ($delta < 60) return 'à l\'instant';
    if ($delta < 3600) return 'il y a ' . floor($delta / 60) . ' min';
    if ($delta < 86400) return 'il y a ' . floor($delta / 3600) . ' h';
    return 'il y a ' . floor($delta / 86400) . ' j';
}

$postsError = (is_array($posts) && isset($posts['error'])) ? $posts['error'] : null;
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MYM Unlock</title>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 2rem 1rem; font: 15px/1.5 system-ui, sans-serif; background: #0d0f14; color: #e7e9ee; }
  .wrap { max-width: 860px; margin: 0 auto; }
  h1 { font-size: 1.35rem; margin: 0 0 .3rem; }
  .sub { color: #8b93a7; margin: 0 0 1.2rem; font-size: .87rem; }
  form { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.6rem; }
  input { flex: 1 1 260px; padding: .62rem .75rem; border-radius: 8px; border: 1px solid #262b36; background: #14171e; color: inherit; }
  input:focus { outline: 2px solid #4c7dff; }
  button { padding: .62rem 1.1rem; border-radius: 8px; border: 0; background: #4c7dff; color: #fff; font-weight: 600; cursor: pointer; }
  button:hover { background: #3964e0; }
  .card { border: 1px solid #262b36; border-radius: 10px; padding: 1rem; margin-bottom: 1rem; background: #12151b; }
  .tag { display: inline-block; padding: .14rem .5rem; border-radius: 99px; font-size: .72rem; font-weight: 600; }
  .locked { background: #42210f; color: #ffb27a; }
  .free { background: #10301f; color: #74e2a0; }
  .state { color: #8b93a7; font-size: .84rem; }
  video { width: 100%; max-height: 70vh; border-radius: 8px; background: #000; margin-top: .6rem; }
  img.preview { width: 100%; border-radius: 8px; margin-top: .6rem; }
  a.dl { display: inline-block; margin-top: .55rem; margin-right: 1rem; font-size: .84rem; color: #7aa2ff; }
  ul.posts { list-style: none; padding: 0; margin: 0; }
  ul.posts li { padding: .5rem 0; border-bottom: 1px solid #1d222c; font-size: .86rem; }
  ul.posts a { color: #7aa2ff; text-decoration: none; }
  code { font-size: .78rem; color: #9aa4bb; word-break: break-all; }
  .err { border-color: #4a2323; background: #1c1213; color: #ff9a9a; }
  .banner { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; border: 1px solid #262b36; border-radius: 10px; padding: .6rem .8rem; margin-bottom: 1.2rem; font-size: .84rem; background: #12151b; }
  .dot { width: .6rem; height: .6rem; border-radius: 99px; flex: 0 0 auto; }
  .ok { background: #35c46f; }
  .warn { background: #e0a33a; }
  .bad { background: #e05656; }
  .banner a { color: #7aa2ff; margin-left: auto; }
</style>
</head>
<body>
<div class="wrap">
  <h1>MYM Unlock</h1>

  <?php if ($session['state'] === 'connected'): ?>
    <div class="banner">
      <span class="dot ok"></span>
      <span>Connecté à MYM — <?= h($session['account'] ?: 'compte fan') ?> · session <?= h($session['source']) ?> · contrôlée <?= h(unlock_ago($session['refreshed_at'])) ?></span>
      <a href="session.php">état</a>
    </div>
  <?php elseif ($session['state'] === 'expired'): ?>
    <div class="banner">
      <span class="dot warn"></span>
      <span>Session expirée — les médias verrouillés resteront en 403 jusqu'au prochain rafraîchissement.</span>
      <a href="session.php">état</a>
    </div>
  <?php else: ?>
    <div class="banner">
      <span class="dot bad"></span>
      <span>Aucune session fan — les médias verrouillés ne sont pas lisibles.</span>
      <a href="session.php">connecter</a>
    </div>
  <?php endif; ?>

  <p class="sub">Colle l'URL d'un post MYM ou un UUID, avec le pseudo. Le contenu verrouillé est résolu via la session fan du serveur.</p>

  <form method="get" action="">
    <input name="u" placeholder="pseudo du créateur" value="<?= h($username) ?>">
    <input name="t" placeholder="URL du post ou UUID" value="<?= h($rawInput) ?>">
    <button type="submit">Analyser</button>
  </form>

  <?php if ($postsError !== null): ?>
    <div class="card err"><?= h($postsError) ?></div>
  <?php elseif (is_array($posts) && $posts): ?>
    <div class="card">
      <strong>Posts récents</strong>
      <ul class="posts">
      <?php foreach ($posts as $post): ?>
        <li>
          <span class="tag <?= ($post['gate'] ?? 'none') === 'none' ? 'free' : 'locked' ?>"><?= h($post['gate'] ?? '?') ?></span>
          <a href="?u=<?= h(rawurlencode($username)) ?>&amp;t=<?= h($post['id']) ?>"><?= h($post['id']) ?></a>
          <?php if (!empty($post['duration_seconds'])): ?> <code><?= (int)$post['duration_seconds'] ?> s</code><?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    </div>
  <?php elseif (is_array($posts) && !$posts): ?>
    <div class="card err">Aucun post retourné pour ce pseudo.</div>
  <?php endif; ?>

  <?php if (is_array($result)): ?>
    <?php if (isset($result['error'])): ?>
      <div class="card err"><?= h($result['error']) ?></div>
    <?php else: ?>
      <div class="card">
        <span class="tag <?= $result['locked'] ? 'locked' : 'free' ?>"><?= $result['locked'] ? 'verrouillé' : 'libre' ?></span>
        <code>gate=<?= h($result['gate']) ?> · clearance=<?= h($result['clearance']) ?></code>
        <?php if ($result['locked']): ?>
          <p class="state">Contenu réservé aux abonnés — résolu sans abonnement sur ce compte.</p>
        <?php endif; ?>
      </div>

      <?php foreach ($result['items'] as $index => $item): ?>
        <div class="card">
          <?php if (($item['state'] ?? '') === 'accessible'): ?>
            <span class="tag free">HLS <?= h($item['quality']) ?> · session requise</span>
            <?php if (!empty($item['duration'])): ?> <code><?= (int)$item['duration'] ?> s</code><?php endif; ?>
            <video id="v<?= (int)$index ?>" controls playsinline preload="metadata"></video>
            <a class="dl" href="manifest.php?prefix=<?= h(rawurlencode($item['prefix'])) ?>&amp;uuid=<?= h(rawurlencode($item['uuid'])) ?>&amp;q=<?= h($item['quality']) ?>">playlist proxifiée</a>
            <a class="dl" href="dl.php?prefix=<?= h(rawurlencode($item['prefix'])) ?>&amp;uuid=<?= h(rawurlencode($item['uuid'])) ?>&amp;q=<?= h($item['quality']) ?>&amp;raw=1" target="_blank">URL des segments</a>
            <script>
              (function () {
                var el = document.getElementById('v<?= (int)$index ?>');
                var src = <?= json_encode($item['playlist_abs'], JSON_UNESCAPED_SLASHES) ?>;
                if (!src) return;
                if (el.canPlayType('application/vnd.apple.mpegurl')) {
                  el.src = src;
                } else if (window.Hls && window.Hls.isSupported()) {
                  var hls = new window.Hls({ maxBufferLength: 30 });
                  hls.loadSource(src);
                  hls.attachMedia(el);
                }
              })();
            </script>
          <?php elseif (($item['state'] ?? '') === 'image'): ?>
            <span class="tag free">image</span>
            <?php if (!empty($item['thumb'])): ?><img class="preview" src="<?= h($item['thumb']) ?>" alt=""><?php endif; ?>
            <p class="state">Clé directe signée (expire).</p>
          <?php else: ?>
            <span class="tag locked">non résolu</span>
            <p class="state"><?= h($item['state'] ?? '?') ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
