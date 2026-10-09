<?php
require __DIR__ . '/lib.php';
$user  = sanitize_user($_GET['u'] ?? '');
$prof  = $user !== '' ? get_profile($user) : null;
$media = $prof['media'] ?? [];
$photos = array_filter($media, fn($m) => !$m['thumb']);
$thumbs = array_filter($media, fn($m) => $m['thumb']);
$sharp   = array_filter($media, fn($m) => empty($m['blur']));
$blurred = count($media) - count($sharp);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MYM Grabber</title>
<style>
  :root{--bg:#0e0f13;--card:#171922;--line:#262a37;--fg:#e8eaf0;--mut:#8b90a3;--blue:#3b82f6}
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif}
  header{padding:28px 20px;text-align:center}
  h1{margin:0 0 6px;font-size:26px} p.sub{margin:0;color:var(--mut)}
  form{display:flex;gap:10px;max-width:520px;margin:20px auto;padding:0 16px}
  input{flex:1;padding:13px 15px;border-radius:12px;border:1px solid var(--line);background:#101220;color:var(--fg);font-size:15px}
  button{padding:13px 20px;border:0;border-radius:12px;background:var(--blue);color:#fff;font-weight:700;cursor:pointer}
  .bar{max-width:1100px;margin:10px auto;padding:0 16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}
  .bar .meta{color:var(--mut);font-size:14px}
  .bar a.btn{background:#20232e;border:1px solid var(--line);color:var(--fg);text-decoration:none;padding:9px 14px;border-radius:10px;font-size:14px}
  .grid{max-width:1100px;margin:16px auto 60px;padding:0 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;position:relative}
  .card img{width:100%;height:200px;object-fit:cover;display:block;background:#0a0b10}
  .tag{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.65);padding:3px 8px;border-radius:8px;font-size:11px;letter-spacing:.3px}
  .tag.blur{background:#7c3aed} .tag.vid{background:#dc2626;top:34px} .tag.ok{background:#059669}
  .card .dl{position:absolute;top:8px;right:8px;background:rgba(0,0,0,.65);padding:4px 9px;border-radius:8px;font-size:12px;color:#fff;text-decoration:none}
  .err{max-width:700px;margin:24px auto;padding:14px 16px;background:#2a1418;border:1px solid #552;border-radius:12px;color:#fca5a5}
  .note{max-width:700px;margin:18px auto;color:var(--mut);font-size:13px;padding:0 16px;text-align:center}
</style>
</head>
<body>
<header>
  <h1>MYM Grabber</h1>
  <p class="sub">Entre un pseudo créateur → récupère ses médias publics</p>
</header>

<form method="get">
  <input name="u" value="<?= htmlspecialchars($user) ?>" placeholder="ex : Gitanenprison" required>
  <button type="submit">Récupérer</button>
</form>

<?php if ($prof && !empty($prof['notfound'])): ?>
  <div class="err">Profil <b><?= htmlspecialchars($user) ?></b> introuvable.</div>
<?php elseif ($prof && !empty($prof['error'])): ?>
  <div class="err">Erreur : <?= htmlspecialchars($prof['error']) ?></div>
<?php elseif ($prof): ?>
  <div class="bar">
    <div class="meta">
      <b><?= htmlspecialchars($prof['title']) ?></b> — <?= (int)$prof['posts'] ?> posts ·
      <?= count($photos) ?> médias · <?= count($thumbs) ?> vidéos ·
      <span title="Médias non floutés">✔ <?= count($sharp) ?> nets</span> ·
      <span title="Médias encore floutés (tentatives en cours)">░ <?= $blurred ?> floutés</span>
    </div>
    <div>
      <a class="btn" href="?u=<?= urlencode($user) ?>">↻ Rafraîchir</a>
      <?php if ($photos): ?>
        <a class="btn" href="zip.php?u=<?= urlencode($user) ?>">⬇ Tout (ZIP)</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$media): ?>
    <div class="err">Aucun média public trouvé (profil peut-être privé ou SPA).</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($media as $m): ?>
        <div class="card">
          <a href="media.php?u=<?= urlencode($user) ?>&id=<?= rawurlencode($m['id']) ?>" target="_blank">
            <img loading="lazy" src="media.php?u=<?= urlencode($user) ?>&id=<?= rawurlencode($m['id']) ?>" alt="">
          </a>
          <?php if (!empty($m['deblurred'])): ?><span class="tag ok">déflouté ✓</span><?php endif; ?>
          <?php if ($m['blur']): ?><span class="tag blur">flouté</span><?php endif; ?>
          <?php if ($m['thumb'] || $m['is_vid']): ?><span class="tag vid">vidéo <?= htmlspecialchars($m['dur']) ?></span><?php endif; ?>
          <a class="dl" href="media.php?u=<?= urlencode($user) ?>&id=<?= rawurlencode($m['id']) ?>&dl=1">⬇</a>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="note">
      « flouté » = aperçu gratuit d'un contenu verrouillé. Défloutage best-effort continu :
      la variante non floutée est tentée via les cookies CloudFront (policy wildcard)
      puis via la signature d'origine, et retentée tant qu'elle n'a pas répondu 200.
      Le proxy récupère aussi une nouvelle URL signée lorsque l'ancienne expire.
    </p>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>
