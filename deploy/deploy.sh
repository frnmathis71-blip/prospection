#!/usr/bin/env bash
# Codenyr Prospection — mise à jour sur le VPS (M12).
# À lancer depuis /var/www/prospection, en tant que www-data :  sudo -u www-data bash deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

php artisan down --retry=60 || true

git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force

php artisan optimize:clear
php artisan optimize
php artisan filament:optimize

# Horizon termine les tâches en cours puis redémarre avec le nouveau code (relancé par Supervisor).
php artisan horizon:terminate

php artisan up

php artisan app:production-check
