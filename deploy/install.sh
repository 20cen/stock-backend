#!/usr/bin/env bash
# Première installation de l'API sur un hébergement cPanel (Terminal), depuis le dossier du projet :
#
#   bash deploy/install.sh
#
# Vérifie PHP et ses extensions, installe les dépendances, crée le .env de production (les secrets sont
# demandés au clavier, jamais écrits dans le dépôt), crée les tables et le compte propriétaire, puis met en cache.
# Si « php » n'est pas en 8.3 : PHP=/opt/alt/php83/usr/bin/php bash deploy/install.sh
set -euo pipefail
cd "$(dirname "$0")/.."

PHP="${PHP:-php}"
say() { printf '\n\033[1m%s\033[0m\n' "$1"; }
fail() { printf '\n\033[31mÉchec : %s\033[0m\n' "$1" >&2; exit 1; }

say "1/7 Vérification de PHP"
# Le projet ne doit jamais être sous public_html : .env (mot de passe de la base) serait téléchargeable.
case "$(pwd -P)" in
  */public_html/*) fail "le projet est dans un dossier publié sur le web ($(pwd -P)).
Déplacez-le dans votre dossier personnel (ex. ~/stock-backend) et reliez seulement son dossier public au sous-domaine :
voir le guide de déploiement, étape 2." ;;
esac
"$PHP" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || fail "PHP 8.3 ou plus est requis (version actuelle : $("$PHP" -r 'echo PHP_VERSION;')).
Dans cPanel > « Sélectionner une version de PHP », choisissez 8.3 ou 8.4, puis relancez.
Si le Terminal reste sur une ancienne version : PHP=/opt/alt/php83/usr/bin/php bash deploy/install.sh"
echo "PHP $("$PHP" -r 'echo PHP_VERSION;')"

missing=""
# Pas de curl : l'application ne fait aucun appel sortant et Composer s'en passe (le PHP du Terminal
# cPanel n'a pas toujours les mêmes extensions que celui du site).
for ext in pdo_pgsql bcmath mbstring dom xml xmlreader xmlwriter simplexml gd zip fileinfo iconv ctype openssl tokenizer; do
  "$PHP" -m | grep -qix "$ext" || missing="$missing $ext"
done
[ -z "$missing" ] || fail "extensions PHP manquantes :$missing
Activez-les dans cPanel > « Sélectionner une version de PHP » > Extensions, puis relancez."
echo "Extensions requises présentes."

say "2/7 Dépendances (Composer)"
# composer.phar local, exécuté par le même PHP (le « composer » de cPanel peut utiliser une autre version).
[ -f composer.phar ] || curl -sS https://getcomposer.org/installer | "$PHP" -- --quiet --filename=composer.phar
"$PHP" composer.phar install --no-dev --optimize-autoloader --no-interaction --no-progress

say "3/7 Configuration (.env)"
if [ -f .env ]; then
  echo ".env existe déjà : conservé (supprimez-le pour tout reconfigurer)."
else
  cp .env.example .env
  read -rp "URL de l'API [https://stockbackend.diamelise.com] : " APP_URL; APP_URL="${APP_URL:-https://stockbackend.diamelise.com}"
  read -rp "Nom de la base PostgreSQL : " DB_DATABASE
  read -rp "Utilisateur de la base : " DB_USERNAME
  read -rsp "Mot de passe de la base (invisible) : " DB_PASSWORD; echo
  read -rp "Fuseau horaire de la boutique [Africa/Abidjan] : " APP_TIMEZONE; APP_TIMEZONE="${APP_TIMEZONE:-Africa/Abidjan}"
  "$PHP" -r 'exit(in_array($argv[1], timezone_identifiers_list(), true) ? 0 : 1);' "$APP_TIMEZONE" \
    || fail "fuseau horaire inconnu : $APP_TIMEZONE (exemples : Africa/Abidjan, Africa/Dakar, Europe/Paris)."
  read -rp "Nom de la boutique [EMPIL] : " SHOP_NAME; SHOP_NAME="${SHOP_NAME:-EMPIL}"

  SET_APP_NAME="EMPIL" SET_APP_ENV=production SET_APP_DEBUG=false SET_APP_URL="$APP_URL" \
  SET_APP_TIMEZONE="$APP_TIMEZONE" SET_SHOP_NAME="$SHOP_NAME" SET_LOG_LEVEL=warning \
  SET_DB_CONNECTION=pgsql SET_DB_HOST=localhost SET_DB_PORT=5432 \
  SET_DB_DATABASE="$DB_DATABASE" SET_DB_USERNAME="$DB_USERNAME" SET_DB_PASSWORD="$DB_PASSWORD" \
    "$PHP" deploy/set-env.php APP_NAME APP_ENV APP_DEBUG APP_URL APP_TIMEZONE SHOP_NAME LOG_LEVEL \
      DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
  unset DB_PASSWORD
  chmod 600 .env
  "$PHP" artisan key:generate --force
fi

say "4/7 Dossiers en écriture"
mkdir -p storage/app/private storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R u+rwX,g+rwX storage bootstrap/cache

say "5/7 Base de données (tables)"
"$PHP" artisan config:clear >/dev/null
"$PHP" artisan migrate --force || fail "migrations impossibles. Vérifiez dans cPanel > Bases de données PostgreSQL
que l'utilisateur est bien ajouté à la base avec tous les privilèges, et le mot de passe (supprimez .env puis relancez)."

say "6/7 Données initiales (rôles, emplacements, modes de paiement, compte propriétaire)"
# Mot de passe choisi maintenant (transmis au seeder par l'environnement, jamais écrit dans .env).
OWNER_PASSWORD=""
while :; do
  read -rsp "Mot de passe du compte « proprietaire » (10 caractères minimum, vide = aléatoire) : " OWNER_PASSWORD; echo
  [ -z "$OWNER_PASSWORD" ] && break
  [ "${#OWNER_PASSWORD}" -ge 10 ] || { echo "Trop court."; continue; }
  read -rsp "Confirmez : " OWNER_CONFIRM; echo
  [ "$OWNER_PASSWORD" = "$OWNER_CONFIRM" ] && break
  echo "Les deux saisies diffèrent."
done
INITIAL_OWNER_PASSWORD="$OWNER_PASSWORD" "$PHP" artisan db:seed --force
unset OWNER_PASSWORD OWNER_CONFIRM

say "7/7 Mise en cache"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

say "Installation terminée."
echo "Connexion : identifiant « proprietaire » et le mot de passe choisi (ou celui affiché à l'étape 6 s'il était aléatoire)."
echo "Vérification : ouvrez $(grep -E '^APP_URL=' .env | cut -d= -f2- | tr -d '"')/up dans un navigateur (page « Application up »)."
