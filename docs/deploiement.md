# Mise en production — Codenyr Prospection (M12)

Guide pas à pas pour un VPS Ubuntu 24.04 (Hetzner, OVH…). Les fichiers prêts à l'emploi sont dans `deploy/`.
Aucun secret n'est écrit ici : tout va dans le `.env` du serveur, que vous remplissez vous-même.

## 1. Avant de commencer

- Le **domaine d'envoi dédié** (jamais codenyr.fr) a SPF, DKIM et DMARC configurés, et sa boîte est préchauffée
  depuis 3 à 4 semaines.
- Un **sous-domaine** pour l'outil (ex. `prospection.votre-domaine.fr`) pointe vers l'IP du VPS (enregistrement A).
- Vous avez les accès SMTP **et** IMAP de la boîte d'envoi.

## 2. Installer le serveur

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server supervisor git unzip certbot python3-certbot-nginx \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
  php8.4-intl php8.4-bcmath php8.4-gd
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

Base de données (choisissez un mot de passe long, gardé dans votre gestionnaire de mots de passe) :

```bash
sudo mysql -e "CREATE DATABASE codenyr_prospection CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'prospection'@'localhost' IDENTIFIED BY 'MOT_DE_PASSE';"
sudo mysql -e "GRANT ALL ON codenyr_prospection.* TO 'prospection'@'localhost';"
```

## 3. Installer l'application

Le dépôt est privé : créez une clé SSH pour www-data sur le serveur (`sudo -u www-data ssh-keygen -t ed25519`)
et ajoutez la clé publique comme « Deploy key » en lecture seule (GitHub → dépôt → Settings → Deploy keys).

```bash
sudo mkdir -p /var/www/prospection && sudo chown www-data:www-data /var/www/prospection
sudo -u www-data git clone git@github.com:frnmathis71-blip/prospection.git /var/www/prospection
cd /var/www/prospection
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
```

Remplir le `.env` (`sudo -u www-data nano .env`) :

| Variable | Valeur en production |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://prospection.votre-domaine.fr` (les liens de désinscription en dépendent) |
| `DB_*` | MySQL créé ci-dessus |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `redis` |
| `MAIL_*` | SMTP du domaine d'envoi dédié (`MAIL_MAILER=smtp`) |
| `SENDER_NAME`, `SENDER_ADDRESS`, `SENDER_SIRET` | identité du pied de page légal |
| `IMAP_*` | boîte d'envoi (réponses et rebonds) |
| `SENDING_DAILY_CAP` | `10` au départ |
| `BACKUP_ARCHIVE_PASSWORD` | mot de passe long, **copié aussi hors du serveur** |
| `BACKUP_NOTIFY_EMAIL` | votre adresse |
| `AUTH_TWO_FACTOR_REQUIRED` | ne pas mettre `false` : la 2FA est imposée en production |

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --class=TemplateSeeder --force
sudo -u www-data php artisan make:filament-user
sudo -u www-data php artisan optimize && sudo -u www-data php artisan filament:optimize
```

## 4. HTTPS, Horizon et planificateur

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/prospection   # remplacer le nom de domaine dedans
sudo ln -s /etc/nginx/sites-available/prospection /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d prospection.votre-domaine.fr               # HTTPS + renouvellement automatique

sudo cp deploy/horizon.conf /etc/supervisor/conf.d/horizon.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start horizon

sudo crontab -u www-data deploy/crontab
sudo systemctl enable nginx php8.4-fpm mysql redis-server supervisor
```

Puis :

```bash
sudo -u www-data php artisan app:production-check   # doit répondre « tout est en ordre »
sudo -u www-data php artisan schedule:list          # envois, relances, boîte, coupe-circuit, purge, sauvegardes…
sudo -u www-data php artisan app:read-inbox         # teste la connexion IMAP
```

Première connexion sur `https://prospection.votre-domaine.fr/admin` : configuration de la 2FA avec votre téléphone.

## 5. Mises à jour

```bash
cd /var/www/prospection && sudo -u www-data bash deploy/deploy.sh
```

## 6. Sauvegardes : tester la restauration (vérification manuelle)

La base est sauvegardée chaque nuit à 1 h 30 (archive chiffrée dans `storage/app/backups/codenyr-prospection/`,
ou sur le disque distant de `BACKUP_DISKS`), les anciennes sont nettoyées, et un email vous prévient si la dernière
sauvegarde a plus d'un jour.

```bash
sudo -u www-data php artisan backup:run --only-db     # une sauvegarde immédiate
sudo -u www-data php artisan backup:list
```

Restauration sur une **base de test** (jamais sur la base de production) :

```bash
sudo mysql -e "CREATE DATABASE prospection_restauration;"
cd /tmp && unzip -P 'BACKUP_ARCHIVE_PASSWORD' /var/www/prospection/storage/app/backups/codenyr-prospection/<fichier>.zip
sudo mysql prospection_restauration < db-dumps/mysql-codenyr_prospection.sql
sudo mysql prospection_restauration -e "SELECT COUNT(*) FROM companies; SELECT COUNT(*) FROM suppression_list;"
sudo mysql -e "DROP DATABASE prospection_restauration;" && rm -rf /tmp/db-dumps
```

Les nombres doivent correspondre à ceux du « Registre RGPD » dans l'outil.

## 7. Redémarrage (vérification manuelle)

```bash
sudo reboot
# après reconnexion :
sudo supervisorctl status horizon      # RUNNING
sudo -u www-data php artisan horizon:status
sudo -u www-data php artisan app:queue-check
```

Le tableau de bord doit afficher « Worker : Actif » dans la minute.

## 8. Campagne pilote : 10 vrais prospects

C'est **vous** qui lancez l'envoi réel.

1. Vérifier la liste d'opposition, puis relire les templates J0 / J+4 / J+12 (Templates → Aperçu) et s'envoyer un test.
2. Campagnes → Créer :
   - séquence « J0 / J+4 / J+12 — sans site » (ou « site obsolète », avec l'état de site correspondant) ;
   - liste ciblée : état du site, **score minimum 70**, **nombre maximum 10** ;
   - rythme : 10 par jour, 3 par heure.
3. Ouvrir la campagne, vérifier le nombre de destinataires dans la fenêtre de lancement, cocher la confirmation, lancer.
4. Pendant une semaine : suivre le tableau de bord (rebonds, désinscriptions, réponses) et répondre vite.
5. Seulement ensuite : augmenter progressivement `SENDING_DAILY_CAP` et la taille des campagnes
   (objectif 100 à 150 emails par semaine).

## 9. RGPD au quotidien

- **Registre RGPD** (menu) : fiche du traitement et volumes.
- **Demande d'accès** : « Exporter les données d'une personne » (Registre) ou l'icône de téléchargement dans Contacts ;
  en ligne de commande : `php artisan app:export-person adresse@exemple.fr`.
- **Opposition** : automatique par le lien de désinscription ; manuelle via la liste d'opposition.
- **Purge** : chaque nuit, les prospects sans réponse depuis plus de 3 ans sont supprimés ; la liste d'opposition
  est conservée (`php artisan app:purge-prospects --dry-run` pour voir combien).
