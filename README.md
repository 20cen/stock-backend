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
| GET | `/api/v1/categories` | `catalog.view` |
| POST, PUT | `/api/v1/categories`, `/api/v1/categories/{id}` (`name`, `description`, `active`) | `catalog.manage` |
| GET | `/api/v1/articles` (`search`, `category_id`, `status` = active·inactive·all, `low`, `page`) — stock par emplacement inclus | `catalog.view` |
| GET | `/api/v1/articles/{id}`, `/api/v1/articles/barcode/{code}` (404 si code inconnu) | `catalog.view` |
| POST, PUT | `/api/v1/articles`, `/api/v1/articles/{id}` — pas de suppression, `active: false` ; prix exigent `prices.manage` | `catalog.manage` |
| GET, POST, PUT | `/api/v1/suppliers` (`search`, `status`) — pas de suppression | `suppliers.manage` |
| GET, POST, PUT | `/api/v1/customers` (`search`, `status`) — pas de suppression | `customers.manage` |
| GET | `/api/v1/dashboard` (`period` = today·7d·month·custom, `from`, `to`) — financier si `reports.financial`, sinon ventes du jour | `dashboard.view` |
| GET | `/api/v1/reports` (catalogue + listes de filtres), `/api/v1/reports/{clé}` (`from`, `to`, `category_id`, `location_id`, `cashier_id`, `supplier_id`, `expense_category_id`, `format` = json·xlsx·pdf) | `reports.financial` |
| GET | `/api/v1/payment-methods`, `/api/v1/locations` | connecté |
| GET, POST, PUT | `/api/v1/purchases` (`search`, `status`, `supplier_id`, `unpaid`, `from`, `to`) — PUT sur brouillon seulement ; `confirm: true` valide | `purchases.manage` |
| POST | `/api/v1/purchases/{id}/confirm`, `/cancel` (`reason`), `/payments` (`amount`, `payment_method_id`) | `purchases.manage` |
| POST | `/api/v1/purchases/{id}/receipts` (`location_id`, `lines[]` : `purchase_line_id`, `quantity`) — entrée en stock | `receipts.validate` |
| GET | `/api/v1/cash-sessions/current`, `/api/v1/cash-sessions`, `/api/v1/cash-sessions/{id}` | `cash.operate` |
| POST | `/api/v1/cash-sessions` (`opening_amount`), `/api/v1/cash-sessions/{id}/close` (`declared_cash`, `variance_reason`) | `cash.operate` |
| GET | `/api/v1/sales/config`, `/api/v1/sales/customers?search=` | `sales.create` |
| POST | `/api/v1/sales` (`lines[]` : `article_id`, `quantity`, `discount` ; `payments[]` : `payment_method_id`, `amount` ; `customer_id`) | `sales.create` |
| GET | `/api/v1/sales`, `/api/v1/sales/{id}` — les siennes, ou toutes avec `sales.view_all` | `sales.view_own` |
| POST | `/api/v1/sales/{id}/cancel` (`reason`) — remise en stock et remboursement des paiements | `sales.cancel` |
| GET | `/api/v1/returns`, `/api/v1/returns/{id}`, `/api/v1/returns/sale?reference=VTE-…` | `returns.request` |
| POST | `/api/v1/returns` (`sale_id`, `reason`, `lines[]` : `sale_line_id`, `quantity`, `condition` good·damaged) | `returns.request` |
| POST | `/api/v1/returns/{id}/validate` (`destinations`, `refund`), `/reject` (`reason`), `/refunds` (`amount`, `payment_method_id`) | `returns.validate` |
| GET, POST | `/api/v1/losses` (`article_id`, `location_id`, `quantity`, `reason`, `comment`), `GET /losses/reasons` | `losses.declare` |
| POST | `/api/v1/losses/{id}/validate`, `/reject` (`reason`) | `losses.validate` |
| GET, POST | `/api/v1/inventories` (`location_id`, `category_id`), `GET /inventories/{id}` | `inventories.manage` |
| PUT, POST | `/api/v1/inventories/{id}/counts` (`lines[]` : `article_id`, `counted_quantity`, `reason`), `/validate`, `/cancel` | `inventories.manage` |
| GET, POST, PUT | `/api/v1/expense-categories` | `expenses.manage` |
| GET, POST | `/api/v1/expenses` (`from`, `to`, `category_id`, `status`, `search` ; renvoie `total_amount`) — POST en multipart avec `attachment` | `expenses.manage` |
| PUT, POST, GET | `/api/v1/expenses/{id}` (sans montant ni mode), `/attachment` (envoi / téléchargement), `/cancel` (`reason`) | `expenses.manage` |
| GET, POST | `/api/v1/transfers` (`search`, `location_id`, `from`, `to`) — validation immédiate, pas de modification | `transfers.manage` |
| GET | `/api/v1/stock/summary` — articles, ruptures, stock faible ; valeur au CMP si `prices.manage` | `stock.view` |
| GET | `/api/v1/stock/balances` (`article_id`, `location_id`, `search`, `low`) | `stock.view` |
| GET | `/api/v1/stock/movements` (`article_id`, `location_id`, `type`, `from`, `to`) | `stock.view` |
| POST | `/api/v1/stock/adjustments` (`article_id`, `location_id`, `quantity` signée ≤ 3 décimales, `reason`, `unit_cost`) | `stock.adjust` |

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
- `app/Services/PurchaseService.php` — acquisitions (brouillon → commandée → partiellement reçue → reçue, ou annulée),
  réceptions (seules les quantités reçues entrent en stock, à l'emplacement choisi) et paiements fournisseurs. Le coût
  d'entrée = prix net de remise + part des frais au prorata du montant des lignes.
- `app/Services/SaleService.php` — vente atomique : caisse ouverte obligatoire, prix du catalogue, remise du caissier
  plafonnée (`settings` : `sales.cashier_discount_limit_percent`, 5 % par défaut), paiement mixte, monnaie rendue
  sur les espèces, crédit seulement si `sales.allow_credit` et client identifié ; CMP figé sur chaque ligne.
- `app/Services/CashSessionService.php` — ouverture (une session ouverte par caissier, index unique partiel) et
  clôture : solde théorique par mode, espèces comptées, écart justifié obligatoire.
- `app/Services/ReturnService.php` — retours : demande sans effet sur le stock, validation qui fixe la destination
  (endommagé → emplacement « défectueux » uniquement), quantité retournable = vendue − retours en cours/validés,
  délai `returns.max_days` (7 j) ; remboursement distinct, plafonné au payé, sorti de la caisse de celui qui rembourse.
- `app/Services/LossService.php` — pertes motivées ; sortie de stock à la validation ; validation directe pour un
  responsable ou sous le seuil `losses.auto_validate_limit` (0 par défaut : toutes soumises).
- `app/Services/InventoryService.php` — inventaire par emplacement (un seul en cours) : théorique figé à l'ouverture,
  comptages sans effet, validation = mouvements « écart d'inventaire » (compté − théorique figé), non comptés ignorés.
- `app/Services/ExpenseService.php` — dépenses (catégorie obligatoire), justificatif sur le disque `local` (privé),
  sortie du tiroir-caisse déduite des espèces attendues ; correction des champs descriptifs tracée, montant et mode
  non modifiables (annuler puis ressaisir).
- `app/Services/DocumentNumber.php` — numéros annuels sans trou (`ACH-2026-00001`, `REC-2026-00001`).
- `app/Exceptions/BusinessException.php` — règle de gestion non respectée → 422 avec message en français.
- Quantités en `DECIMAL(14,3)` (chaînes côté PHP, calculs en bcmath via `app/Support/Decimal.php`). Un article
  n'accepte des décimales que si `allow_decimal_quantity` est vrai (câble au mètre…).
- Valorisation au **coût moyen pondéré** par article (`articles.average_cost`, 4 décimales) : recalculé à chaque entrée
  valorisée ; sorties et transferts valorisés au CMP courant. Chaque mouvement garde son `unit_cost`.
