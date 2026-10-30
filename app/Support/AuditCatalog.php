<?php

namespace App\Support;

use App\Models\Article;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Inventory;
use App\Models\Loss;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Receipt;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\User;

/**
 * Libellés français du journal d'audit : actions, modules et entités concernées.
 */
class AuditCatalog
{
    /** @var array<string, string> */
    public const ACTIONS = [
        'auth.login' => 'Connexion',
        'auth.login_failed' => 'Échec de connexion',
        'auth.login_refused_inactive' => 'Connexion refusée (compte désactivé)',
        'auth.logout' => 'Déconnexion',
        'auth.password_changed' => 'Mot de passe changé',
        'auth.password_change_failed' => 'Changement de mot de passe refusé',
        'user.created' => 'Utilisateur créé',
        'user.updated' => 'Utilisateur modifié',
        'article.created' => 'Article créé',
        'article.updated' => 'Article modifié',
        'article.cost_set' => 'Coût moyen défini',
        'category.created' => 'Catégorie créée',
        'category.updated' => 'Catégorie modifiée',
        'supplier.created' => 'Fournisseur créé',
        'supplier.updated' => 'Fournisseur modifié',
        'customer.created' => 'Client créé',
        'customer.updated' => 'Client modifié',
        'purchase.created' => 'Acquisition créée',
        'purchase.updated' => 'Acquisition modifiée',
        'purchase.ordered' => 'Acquisition validée',
        'purchase.cancelled' => 'Acquisition annulée',
        'purchase.closed' => 'Acquisition clôturée',
        'purchase.payment_added' => 'Paiement fournisseur',
        'purchase.payment_cancelled' => 'Paiement fournisseur annulé',
        'receipt.validated' => 'Réception validée',
        'stock.adjusted' => 'Correction de stock',
        'transfer.validated' => 'Transfert validé',
        'cash.opened' => 'Caisse ouverte',
        'cash.closed' => 'Caisse clôturée',
        'sale.created' => 'Vente',
        'sale.cancelled' => 'Vente annulée',
        'return.requested' => 'Retour demandé',
        'return.validated' => 'Retour validé',
        'return.rejected' => 'Retour refusé',
        'refund.created' => 'Remboursement',
        'loss.declared' => 'Perte déclarée',
        'loss.validated' => 'Perte validée',
        'loss.rejected' => 'Perte refusée',
        'inventory.started' => 'Inventaire ouvert',
        'inventory.validated' => 'Inventaire validé',
        'inventory.cancelled' => 'Inventaire annulé',
        'expense.created' => 'Dépense enregistrée',
        'expense.updated' => 'Dépense modifiée',
        'expense.attachment' => 'Justificatif de dépense',
        'expense.cancelled' => 'Dépense annulée',
        'expense_category.created' => 'Catégorie de dépense créée',
        'expense_category.updated' => 'Catégorie de dépense modifiée',
        'settings.update' => 'Paramètres modifiés',
        'settings.logo_updated' => 'Logo changé',
        'settings.logo_removed' => 'Logo retiré',
        'payment_method.create' => 'Mode de paiement ajouté',
        'payment_method.update' => 'Mode de paiement modifié',
        'backup.created' => 'Sauvegarde créée',
        'backup.downloaded' => 'Sauvegarde téléchargée',
    ];

    /** Modules (préfixe de l'action) proposés comme filtre. @var array<string, string> */
    public const MODULES = [
        'auth' => 'Connexions',
        'user' => 'Utilisateurs',
        'article' => 'Articles',
        'category' => 'Catégories',
        'supplier' => 'Fournisseurs',
        'customer' => 'Clients',
        'purchase' => 'Achats',
        'receipt' => 'Réceptions',
        'stock' => 'Stock',
        'transfer' => 'Transferts',
        'cash' => 'Caisse',
        'sale' => 'Ventes',
        'return' => 'Retours',
        'refund' => 'Remboursements',
        'loss' => 'Pertes',
        'inventory' => 'Inventaires',
        'expense' => 'Dépenses',
        'expense_category' => 'Catégories de dépenses',
        'settings' => 'Paramètres',
        'payment_method' => 'Modes de paiement',
        'backup' => 'Sauvegardes',
    ];

    /** Entité : [libellé, colonne lisible]. @var array<class-string, array{0: string, 1: string}> */
    public const ENTITIES = [
        Article::class => ['Article', 'reference'],
        CashSession::class => ['Session de caisse', 'reference'],
        Category::class => ['Catégorie', 'name'],
        Customer::class => ['Client', 'name'],
        Expense::class => ['Dépense', 'reference'],
        ExpenseCategory::class => ['Catégorie de dépense', 'name'],
        Inventory::class => ['Inventaire', 'reference'],
        Loss::class => ['Perte', 'reference'],
        PaymentMethod::class => ['Mode de paiement', 'name'],
        Purchase::class => ['Acquisition', 'reference'],
        Receipt::class => ['Réception', 'reference'],
        Refund::class => ['Remboursement', 'reference'],
        Sale::class => ['Vente', 'reference'],
        SaleReturn::class => ['Retour', 'reference'],
        StockMovement::class => ['Mouvement de stock', 'id'],
        Supplier::class => ['Fournisseur', 'name'],
        Transfer::class => ['Transfert', 'reference'],
        User::class => ['Utilisateur', 'name'],
    ];

    public static function action(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }
}
