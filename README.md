# stock-backend

API REST (Laravel 13 + PostgreSQL) de l'application de gestion de stock, d'achats et de ventes.

Elle est consommée par trois clients qui partagent la même interface :
[stock-frontend](https://github.com/20cen/stock-frontend) (navigateur),
[stock-desktop](https://github.com/20cen/stock-desktop) (Electron) et
[stock-mobile](https://github.com/20cen/stock-mobile) (Capacitor).

Toutes les règles métier, autorisations et écritures de stock sont exécutées ici : un client ne
considère une opération comme enregistrée qu'après la réponse du serveur.

## Prérequis

- PHP 8.3+ avec `pdo_pgsql`, Composer
- PostgreSQL 17

## Installation

```bash
composer install
cp .env.example .env          # renseigner DB_PASSWORD
php artisan key:generate
createdb -U postgres stock     # ou via pgAdmin
php artisan migrate --seed     # affiche le mot de passe du compte propriétaire
php artisan serve              # http://127.0.0.1:8000
```

Le seeder crée les rôles et permissions, les emplacements (Magasin, Boutique, Retours / Défectueux)
et un compte propriétaire (`INITIAL_OWNER_USERNAME` / `INITIAL_OWNER_PASSWORD`, aléatoire si vide).
Il peut être relancé sans risque pour resynchroniser la matrice des droits.

## Tests

```bash
php artisan test               # SQLite en mémoire par défaut
DB_CONNECTION=pgsql DB_DATABASE=stock_test php artisan test   # sur PostgreSQL (comme la CI)
```

Les tests reprennent les identifiants du plan de recette (`TST-001`, `TST-002`…).

Le test de concurrence TST-011 (`tests/Feature/Stock/StockConcurrencyTest.php`) lance de vrais processus PHP
simultanés : il ne s'exécute que sur PostgreSQL (ignoré sur SQLite).

## API (v1)

Authentification par jeton Sanctum : `Authorization: Bearer <token>`. Durée de vie : `SANCTUM_EXPIRATION` minutes.

| Méthode | Route | Droit |
|---|---|---|
| POST | `/api/v1/auth/login` (`username`, `password`, `device_name`) | public, 5 essais/min |
| GET | `/api/v1/auth/me` | connecté |
| POST | `/api/v1/auth/logout` | connecté |
| GET | `/api/v1/roles` | `users.manage` |
| GET, POST | `/api/v1/users` | `users.manage` |
| PUT | `/api/v1/users/{id}` | `users.manage` |
| GET | `/api/v1/stock/balances` (`article_id`, `location_id`, `search`, `low`) | `stock.view` |
| GET | `/api/v1/stock/movements` (`article_id`, `location_id`, `type`, `from`, `to`) | `stock.view` |
| POST | `/api/v1/stock/adjustments` (`article_id`, `location_id`, `quantity` signée, `reason`, `unit_cost`) | `stock.adjust` |

## Organisation

- `app/Enums/Permission.php` — catalogue des permissions ; chaque code est une habileté Gate (`can:sales.create`).
- `app/Enums/RoleCode.php` — matrice des droits propriétaire / caissier.
- `app/Services/AuditLogger.php` — journal des opérations sensibles (`audit_logs`, en ajout seul).
- `app/Http/Middleware/EnsureUserIsActive.php` — un compte désactivé perd immédiatement l'accès.
- `app/Services/Stock/StockService.php` — **seul point d'écriture du stock**. Toute opération (réception, vente,
  transfert, perte, inventaire, correction) lui passe des `StockChange` ; il les applique dans une transaction,
  verrouille les soldes (`FOR UPDATE`, ordre fixe), refuse tout solde négatif et écrit les mouvements.
- `stock_movements` — journal en ajout seul (quantité signée, solde après mouvement, document source) ; une erreur
  se corrige par un mouvement inverse. `stock_balances` — solde courant, `CHECK (quantity_on_hand >= 0)` sur PostgreSQL.
- `app/Enums/MovementType.php` — types de mouvement ; chacun impose le signe de la quantité.
