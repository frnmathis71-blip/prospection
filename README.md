# Codenyr Prospection

Outil Laravel personnel de prospection pour Codenyr. Cahier des charges : [`docs/cahier-des-charges.md`](docs/cahier-des-charges.md).
Règles de travail pour l'IA : [`CLAUDE.md`](CLAUDE.md).

## Lancer le projet en local (Windows + Herd)

Prérequis : PHP 8.4, Composer, une base MySQL (ou PostgreSQL) et un serveur Redis.

```bash
composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix
cp .env.example .env
php artisan key:generate
# remplir .env (base de données, Redis), puis :
php artisan migrate
php artisan make:filament-user
```

- Interface : http://codenyr-prospection.test/admin (Herd) — première connexion : configuration de la 2FA.
- File d'attente sous Windows : `php artisan queue:work` (Horizon nécessite Linux : `php artisan horizon` sur le VPS).
- Tableau de bord des files : http://codenyr-prospection.test/horizon (connecté avec 2FA).
- Contrôle de la file : `php artisan app:queue-check`.
- Données de démonstration fictives (hors production) : `php artisan db:seed`.
- Import CSV : bouton « Importer un CSV » dans Entreprises. Le fichier est traité par la file d'attente :
  `php artisan queue:work` doit tourner. Une notification (cloche) annonce la fin et donne le rapport des
  lignes en erreur. Un modèle de fichier est téléchargeable dans la fenêtre d'import.
- Tableau de bord : état de la file (tâches en attente, worker arrêté, échecs) et suivi des imports
  (en attente, en cours avec progression, terminé, rapport d'erreurs), mis à jour toutes les 5 secondes.

## Qualité

```bash
php artisan test
./vendor/bin/pint --test
```

La CI GitHub Actions (`.github/workflows/ci.yml`) lance Pint et les tests à chaque push.
