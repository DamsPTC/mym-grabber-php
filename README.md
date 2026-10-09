# MYM Grabber — PHP

Webapp PHP autonome, sans Composer, à installer sur un hébergement PHP tel que Hostinger. Elle lit la page publique d'un profil MYM, extrait les URL de médias présentes dans son HTML, affiche une grille et propose le téléchargement individuel ou en ZIP.

## Fichiers

```text
mym/
├── index.php      Interface et extraction du profil
├── media.php      Proxy média et rafraîchissement des URL
├── zip.php        Téléchargement groupé (120 fichiers maximum)
├── lib.php        Fonctions partagées
└── cache/
    └── .htaccess Accès HTTP au cache interdit (Apache/LiteSpeed)
```

## Installation sur Hostinger

1. Télécharger le dépôt via **Code → Download ZIP**, puis extraire l'archive.
2. Uploader le dossier `mym/` dans `public_html/`, y compris `cache/.htaccess`.
3. Sélectionner une version PHP **8.0 minimum** compatible et maintenue par l'hébergeur, avec les extensions **cURL** et **zip** activées.
4. Donner au processus PHP le droit d'écrire dans `mym/cache/` (permissions 755 ou 775 selon la configuration ; ne pas utiliser 777).
5. Ouvrir `https://votre-domaine/mym/`, puis saisir un pseudo.

Le dépôt GitHub conserve le code ; il n'exécute pas PHP. Le site devient accessible après installation sur l'hébergement PHP. Aucun déploiement Hostinger automatique n'est configuré.

## Fonctionnement et limites

- Le cache du profil dure 90 secondes. Les JSON générés ne sont pas versionnés.
- Le proxy ne **signe pas** lui-même les URL : il relit la page publique et réutilise une URL fournie par MYM. La nouvelle tentative fonctionne uniquement si le même identifiant de média est encore présent et accessible.
- Il ne peut donc pas garantir des liens permanents. Un média supprimé, un profil devenu privé ou un changement de structure HTML peuvent empêcher l'accès.
- Seuls les médias présents dans le HTML initial sont extraits. Il n'y a pas de pagination ni de connexion à une API authentifiée.
- Les aperçus floutés restent floutés. Le code ne modifie pas les chemins de fichiers pour rechercher une version verrouillée.
- La grille du code fourni utilise des images pour les aperçus ; un fichier vidéo direct peut ne pas avoir de vignette affichable.
- L'export ZIP ignore les miniatures, s'arrête à 120 téléchargements réussis et dépend des limites mémoire/durée de l'hébergeur. Il ne réessaie pas les URL expirées pendant l'export.
- Le code charge chaque média en mémoire et ne gère pas les requêtes HTTP Range. Les fichiers volumineux et la navigation dans les vidéos peuvent être limités.
- Le bouton Rafraîchir recharge la page et réutilise le cache tant que ses 90 secondes ne sont pas écoulées.
- Sur Nginx, prévoir une règle serveur interdisant l'accès à `/mym/cache/` : `.htaccess` n'y est pas interprété.

## Vérification

Une action GitHub vérifie la syntaxe des quatre fichiers PHP après chaque push ou pull request. Cette vérification n'effectue aucune requête vers MYM et ne garantit pas la compatibilité avec son HTML actuel.

En local, avec PHP installé :

```sh
find mym -name '*.php' -print0 | xargs -0 -n1 php -l
php -S 127.0.0.1:8080 -t mym
```

Le serveur intégré PHP est destiné au développement.
