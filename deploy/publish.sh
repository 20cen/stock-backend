#!/usr/bin/env bash
# Relie le dossier du sous-domaine (ex. ~/public_html/stockbackend) à l'API, sans y copier le projet
# ni toucher aux fichiers de l'hébergeur (php.ini, .user.ini, .well-known…) :
#   - index.php : relais vers ~/stock-backend/public/index.php ;
#   - .htaccess : ajout des règles de Laravel à la fin du fichier existant (sauvegardé avant).
#
#   bash deploy/publish.sh ~/public_html/stockbackend
set -euo pipefail
cd "$(dirname "$0")/.."

PROJECT="$(pwd -P)"
DOCROOT="${1:?Indiquez le dossier du sous-domaine, ex. ~/public_html/stockbackend}"
[ -d "$DOCROOT" ] || { echo "Dossier introuvable : $DOCROOT" >&2; exit 1; }
DOCROOT="$(cd "$DOCROOT" && pwd -P)"
case "$PROJECT/" in "$DOCROOT"/*) echo "Le projet ne doit pas être dans le dossier du sous-domaine." >&2; exit 1 ;; esac

MARK="# EMPIL-LARAVEL"

# Relais PHP : le projet reste hors du web, seul ce fichier est publié.
if [ -f "$DOCROOT/index.php" ] && ! grep -q "$MARK" "$DOCROOT/index.php"; then
  mv "$DOCROOT/index.php" "$DOCROOT/index.php.avant-empil"
fi
cat > "$DOCROOT/index.php" <<PHP
<?php

// $MARK : relais vers l'API installée hors du dossier publié (deploy/publish.sh).
require '$PROJECT/public/index.php';
PHP

# Règles de réécriture de Laravel, ajoutées une seule fois après celles de l'hébergeur.
touch "$DOCROOT/.htaccess"
if ! grep -q "$MARK" "$DOCROOT/.htaccess"; then
  cp "$DOCROOT/.htaccess" "$DOCROOT/.htaccess.avant-empil"
  cat >> "$DOCROOT/.htaccess" <<HTACCESS

$MARK BEGIN
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Jeton de connexion (en-tête Authorization) transmis à PHP.
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    # Tout ce qui n'est pas un fichier ou un dossier existant (dont .well-known) va à l'API.
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
$MARK END
HTACCESS
fi

echo "Sous-domaine relié : $DOCROOT -> $PROJECT/public"
