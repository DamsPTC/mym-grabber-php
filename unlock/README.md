# MYM Unlock

Déverrouillage du contenu MYM réservé aux abonnés. Deux failles d'audit sont
enchaînées :

1. **Détail de post verrouillé exposé** — `GET /v1/creators/{username}/posts/{postId}`
   répond `200` avec `gate`, `clearance`, `medias[].filename`, `created_at` et
   `thumbnail_url` alors que `gate="subscription"` et `clearance="required"`,
   sans abonnement et sans session. Seul `url` vaut `null`.
2. **Segments HLS servis sans signature** — sur `media.prod-v2.mym.fans`, la
   playlist variante `.m3u8` et tous les segments `.ts` du préfixe `/HLS/` sont
   accessibles sans `Policy` / `Signature` / `Key-Pair-Id`. Les images, elles,
   renvoient `403 MissingKey`.

Le dossier S3 de la vidéo est porté par `thumbnail_url`, dont le chemin
`/medias/v2/{base64url}` décode un JSON contenant
`key: medias/AAAA/MM/JJ/{uuid}/thumbnails/...`. Le préfixe en découle, et le flux
complet se reconstruit depuis là.

## Fichiers

```text
unlock.php          Accès depuis la racine du domaine (charge unlock/index.php)
unlock/
├── index.php       Interface : pseudo, puis post à analyser
├── lib.php         Résolution API + reconstruction du chemin HLS
├── segment.php     Proxy des segments .ts
├── manifest.php    Playlist réécrite, en téléchargement
└── dl.php          Liste des URL absolues (JSON ou texte brut avec ?raw)
```

Module autonome : il ne dépend pas de `mym/lib.php` et ne lit ni n'écrit aucun
cache. Aucun identifiant n'est nécessaire.

## Usage

1. Déployer le dépôt, ouvrir `https://votre-domaine/unlock.php`.
2. Saisir un pseudo, les posts récents s'affichent avec leur `gate`.
3. Ouvrir un post verrouillé : le lecteur charge le flux, la qualité réelle
   disponible est détectée (1080p → 360p).

### Reconstruction du fichier complet

Le proxy sert les segments un par un. Pour obtenir un fichier unique :

```sh
# 1. récupérer la playlist proxifiée sur le domaine
curl -s 'https://votre-domaine/manifest.php?prefix=medias%2F2026%2F08%2F20%2F{uuid}&uuid={uuid}&q=720p' -o flux.m3u8

# 2. réassembler
ffmpeg -allowed_extensions ALL -i flux.m3u8 -c copy video.ts
```

Les segments restent relatifs à `segment.php` : lancer `ffmpeg` depuis le
répertoire de `flux.m3u8` ne suffit pas, il faut un domaine joignable. Sinon,
utiliser `dl.php?raw` pour obtenir les URL absolues et les télécharger.

## Limites

- **Images verrouillées** : la clé directe reste signée (`403 MissingKey`). Le
  module affiche l'aperçu, pas l'original.
- **Qualités** : seules les variantes effectivement présentes répondent. Sur les
  anciens médias, `720p` et `1080p` fonctionnent mais pas `360p`/`480p`.
- **Playlist master** : `…/HLS/{uuid}.m3u8` reste signée ; le module contourne en
  ciblant directement une variante.
- **Expiration** : les segments n'ont pas de TTL, les URL restent valables. Les
  vignettes, elles, expirent.
- Aucun contournement de DRM : le HLS est servi en clair, sans chiffrement de
  segments.

## Vérification

```sh
find unlock unlock.php -name '*.php' -print0 | xargs -0 -n1 php -l
```
