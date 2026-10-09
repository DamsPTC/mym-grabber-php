# MYM Grabber — PHP

Webapp PHP autonome, sans Composer, à installer sur un hébergement PHP tel que Hostinger. Elle lit la page publique d'un profil MYM, extrait les URL de médias présentes dans son HTML, affiche une grille et propose le téléchargement individuel ou en ZIP.

## Fichiers

```text
index.php          Accueil du domaine (charge mym/index.php)
media.php          Proxy accessible depuis l'accueil du domaine
zip.php            Export accessible depuis l'accueil du domaine
mym/
├── index.php      Interface et extraction du profil
├── media.php      Proxy média et rafraîchissement des URL
├── zip.php        Téléchargement groupé (120 fichiers maximum)
├── lib.php        Fonctions partagées
└── cache/
    └── .htaccess Accès HTTP au cache interdit (Apache/LiteSpeed)
```

## Installation sur Hostinger

### À la racine d'un domaine ou sous-domaine

Déployer **tout le dépôt** depuis la branche `main` dans le répertoire web du domaine ou sous-domaine choisi. Les fichiers `index.php`, `media.php` et `zip.php` doivent être directement dans ce répertoire, à côté du dossier `mym/`. L'application s'ouvre alors sur `https://votre-domaine/`, sans ajouter `/mym/`.

Sur un déploiement existant, cliquer sur **Déployer** dans la section Git de hPanel après avoir récupéré cette version si le déploiement automatique n'est pas configuré.

Un 403 à la racine alors que `/mym/` fonctionne indique que PHP et l'application fonctionnent mais que la racine n'a pas de page d'accueil. Vérifier que le fichier `index.php` situé à la racine du dépôt a bien été déployé. Ne pas copier `mym/cache/.htaccess` à la racine : ce fichier interdit volontairement l'accès au cache uniquement.

### Dans un sous-dossier `/mym/`

1. Télécharger le dépôt via **Code → Download ZIP**, puis extraire l'archive.
2. Uploader le dossier `mym/` dans `public_html/`, y compris `cache/.htaccess`.
3. Sélectionner une version PHP **8.0 minimum** compatible et maintenue par l'hébergeur, avec les extensions **cURL** et **zip** activées.
4. Donner au processus PHP le droit d'écrire dans `mym/cache/` (permissions 755 ou 775 selon la configuration ; ne pas utiliser 777).
5. Ouvrir `https://votre-domaine/mym/`, puis saisir un pseudo.

Le dépôt GitHub conserve le code ; PHP s'exécute sur Hostinger. Le sous-domaine existant récupère les mises à jour de `main` via son déploiement Git. Pour une nouvelle installation, configurer ce déploiement dans hPanel.

## Fonctionnement et limites

- La collecte parcourt le profil avec `?page=1`, `?page=2`, etc., jusqu'à une page vide, une page sans nouveau média ou la limite de 25 pages. Elle ne dépend plus de l'ancien endpoint AJAX `/feed`.
- Seuls les `img.media__image` et les sources vidéo situés dans une grille `medias-grid` sont retenus. Avatars, images hors grille et copies présentes dans les scripts sont exclus. Les doublons sont fusionnés par clé de fichier, en conservant les variantes floutées distinctes.
- Le catalogue est conservé 90 secondes. Chaque page dispose aussi d'un cache de 30 secondes au maximum sous `mym/cache/pages/`. Les JSON générés ne sont pas versionnés.
- Le proxy ne **signe pas** lui-même les URL : il relit la page d'origine et réutilise une URL fournie par MYM. L'expiration est lue dans `Expires` ou dans la politique CloudFront `DateLessThan`, avec une marge de 10 secondes. Un échec du téléchargement déclenche une nouvelle tentative après rafraîchissement de cette page.
- Chaque média conserve sa page d'origine. Si les nouvelles publications l'ont déplacé, un nouveau parcours du profil le recherche par sa clé stable.
- Il ne peut donc pas garantir des liens permanents. Un média supprimé, un profil devenu privé ou un changement de structure HTML peuvent empêcher l'accès.
- Les aperçus floutés restent floutés. Le code ne modifie pas les chemins de fichiers pour rechercher une version verrouillée.
- Le flag `blur` décrit le nom de fichier exposé, y compris quand il est encodé dans le descripteur CDN. Un aperçu sans `-blur` ne prouve pas qu'il s'agit du fichier original ou d'une vidéo complète.
- Une session facultative peut être réutilisée depuis `mym/cache/cookies/mym_fan.jar`. Pour la connexion initiale, configurer `MYM_EMAIL` et `MYM_PASSWORD` dans l'environnement serveur. Aucun identifiant n'est inscrit dans le dépôt. Seuls les médias effectivement exposés par les pages accessibles à cette session sont collectés.
- La grille du code fourni utilise des images pour les aperçus ; un fichier vidéo direct peut ne pas avoir de vignette affichable.
- L'export ZIP ignore les miniatures, s'arrête à 120 téléchargements réussis et dépend des limites mémoire/durée de l'hébergeur. Il utilise le même rafraîchissement par page et réessaie une fois les URL refusées.
- Le code charge chaque média en mémoire et ne gère pas les requêtes HTTP Range. Les fichiers volumineux et la navigation dans les vidéos peuvent être limités.
- Le bouton Rafraîchir recharge la page et réutilise le cache tant que ses 90 secondes ne sont pas écoulées.
- Sur Nginx, prévoir une règle serveur interdisant l'accès à `/mym/cache/` : `.htaccess` n'y est pas interprété.

## Vérification

Une action GitHub vérifie la syntaxe de tous les fichiers PHP et le rendu de l'accueil à la racine après chaque push ou pull request. Cette vérification n'effectue aucune requête vers MYM et ne garantit pas la compatibilité avec son HTML actuel.

En local, avec PHP installé :

```sh
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php -S 127.0.0.1:8080
```

Le serveur intégré PHP est destiné au développement.
