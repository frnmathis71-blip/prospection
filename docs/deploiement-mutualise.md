# Mise en production sur un hébergement mutualisé OVH

Variante de [`deploiement.md`](deploiement.md) (VPS) pour un hébergement web mutualisé OVH (offre Pro ou
supérieure, avec accès SSH). Aucun secret ici : tout va dans le `.env` du serveur, rempli par vous.

## Ce qui change par rapport à un VPS

Un mutualisé OVH n'autorise qu'**une tâche planifiée par heure**, arrêtée au bout de **60 minutes**, sans Redis ni
processus permanent ([documentation OVH](https://docs.ovhcloud.com/fr/guides/web-cloud/web-hosting/cron-tasks)).
L'outil s'y adapte :

| VPS | Mutualisé |
| --- | --- |
| cron `schedule:run` chaque minute | tâche OVH horaire sur `cron-ovh.php` → `app:shared-hosting-cron` : planificateur chaque minute **pendant 55 minutes** |
| Horizon supervisé (Redis) | file d'attente en base (`QUEUE_CONNECTION=database`), traitée entre deux passages du planificateur |
| cache Redis | cache en base (`CACHE_STORE=database`) |

Conséquence : chaque heure, environ 5 minutes sans traitement (le temps que la tâche suivante démarre). Les envois
reprennent ensuite ; les tâches à heure fixe (sauvegarde, purge, recherches récurrentes) partent au premier
passage après leur heure, une seule fois par période. Horizon est fermé : l'état de la file est sur le tableau de bord.

## 1. Préparer l'hébergement (espace client OVH)

- **Version de PHP : 8.4** (fichier `.ovhconfig`, déjà le cas d'après votre invite SSH `php/8.4`).
- **Base de données** : Hébergements → Bases de données → créer une base MySQL ; noter serveur, nom, utilisateur.
- **Multisite** : Hébergements → Multisite → ajouter `prospection.codenyr.fr`, dossier racine **`prospection/public`**,
  avec le certificat SSL (Let's Encrypt) activé.

## 2. Installer le code (SSH)

Le dépôt est privé : créez une clé et ajoutez-la comme « Deploy key » en lecture seule
(GitHub → dépôt → Settings → Deploy keys).

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub          # à coller dans GitHub (Deploy keys)
ls -la ~/prospection                # le dossier doit être vide (sinon le renommer)
git clone git@github.com:frnmathis71-blip/prospection.git ~/prospection
cd ~/prospection
composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
cp .env.example .env
php artisan key:generate
```

Si `composer` n'existe pas : `curl -sS https://getcomposer.org/installer | php`, puis `php composer.phar install …`.

## 3. Le `.env` de production

Comme pour un VPS (voir le tableau de `deploiement.md`), avec ces différences :

```
HOSTING_MODE=shared
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
APP_URL=https://prospection.codenyr.fr
DB_CONNECTION=mysql   # + DB_HOST (serveur fourni par OVH), DB_DATABASE, DB_USERNAME, DB_PASSWORD
```

Puis :

```bash
php artisan migrate --force
php artisan db:seed --class=TemplateSeeder --force
php artisan make:filament-user
php artisan optimize && php artisan filament:optimize
php artisan app:production-check
```

## 4. La tâche planifiée

Espace client OVH → Hébergements → **Tâches planifiées - Cron** → Ajouter :

- **Commande** : `prospection/cron-ovh.php`
- **Langage** : PHP 8.4
- **Fréquence** : toutes les heures, tous les jours
- **Rapport par email** : oui (vous êtes prévenu en cas d'erreur)

Dans l'heure qui suit, le tableau de bord doit afficher « Worker : Actif » (il le reste ~55 minutes par heure).
Pour tester tout de suite, en SSH : `php artisan app:shared-hosting-cron --minutes=2`.

## 5. Vérifications propres au mutualisé

- **Connexions sortantes** : depuis le mutualisé, vérifier que l'envoi SMTP et la lecture IMAP passent
  (`php artisan app:read-inbox`, puis « M'envoyer un test » depuis un template). Si le fournisseur SMTP est refusé,
  utiliser le port 587 (STARTTLS) ou 465.
- **Sauvegardes** : `php artisan backup:run --only-db` doit créer une archive (`mysqldump` est fourni par OVH en SSH).
  En plus, OVH sauvegarde l'hébergement et les bases (restauration depuis l'espace client).
- **Redémarrage** : rien à faire, il n'y a pas de processus permanent ; la tâche horaire repart seule.

## 6. Mises à jour

```bash
cd ~/prospection
php artisan down --retry=60
git pull --ff-only
composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
php artisan migrate --force
php artisan optimize:clear && php artisan optimize && php artisan filament:optimize
php artisan up
```

La boucle en cours finit avec l'ancien code ; la suivante (heure suivante) prend le nouveau.

## 7. Campagne pilote et RGPD

Identiques au VPS : sections 8 et 9 de [`deploiement.md`](deploiement.md).
