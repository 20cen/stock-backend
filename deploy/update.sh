#!/usr/bin/env bash
# Mise à jour de l'API en production, depuis le dossier du projet (Terminal cPanel) :
#
#   bash deploy/update.sh
#
# Récupère la dernière version, met à jour les dépendances, applique les nouvelles migrations
# (jamais de rollback ni de fresh en production) et reconstruit les caches. Le site est en maintenance
# pendant l'opération (quelques secondes).
set -euo pipefail
cd "$(dirname "$0")/.."

PHP="${PHP:-php}"
[ -f composer.phar ] || curl -sS https://getcomposer.org/installer | "$PHP" -- --quiet --filename=composer.phar

"$PHP" artisan down --retry=15 || true
trap '"$PHP" artisan up' EXIT

git pull --ff-only
"$PHP" composer.phar install --no-dev --optimize-autoloader --no-interaction --no-progress
"$PHP" artisan migrate --force
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo "Mise à jour terminée : $(git log -1 --format='%h %s')"
