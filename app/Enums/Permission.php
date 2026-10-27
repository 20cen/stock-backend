<?php

namespace App\Enums;

/**
 * Catalogue des permissions. La matrice par rôle est dans RoleCode::permissions().
 * Chaque code est aussi une habileté Gate : ->middleware('can:sales.create').
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case PricesManage = 'prices.manage';
    case SuppliersManage = 'suppliers.manage';
    case CustomersManage = 'customers.manage';
    case PurchasesManage = 'purchases.manage';
    case ReceiptsValidate = 'receipts.validate';
    case StockView = 'stock.view';
    case StockAdjust = 'stock.adjust';
    case TransfersManage = 'transfers.manage';
    case CashOperate = 'cash.operate';
    case SalesCreate = 'sales.create';
    case SalesViewOwn = 'sales.view_own';
    case SalesViewAll = 'sales.view_all';
    case SalesCancel = 'sales.cancel';
    case DiscountsGrant = 'discounts.grant';
    case ReturnsRequest = 'returns.request';
    case ReturnsValidate = 'returns.validate';
    case LossesDeclare = 'losses.declare';
    case LossesValidate = 'losses.validate';
    case InventoriesManage = 'inventories.manage';
    case ExpensesManage = 'expenses.manage';
    case ReportsFinancial = 'reports.financial';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';

    public function description(): string
    {
        return match ($this) {
            self::DashboardView => 'Consulter le tableau de bord',
            self::CatalogView => 'Consulter le catalogue',
            self::CatalogManage => 'Créer et modifier les articles et catégories',
            self::PricesManage => 'Modifier les prix',
            self::SuppliersManage => 'Gérer les fournisseurs',
            self::CustomersManage => 'Gérer les clients',
            self::PurchasesManage => 'Gérer les acquisitions',
            self::ReceiptsValidate => 'Valider les réceptions',
            self::StockView => 'Consulter les stocks',
            self::StockAdjust => 'Corriger les stocks',
            self::TransfersManage => 'Créer et valider les transferts',
            self::CashOperate => 'Ouvrir et clôturer sa caisse',
            self::SalesCreate => 'Enregistrer des ventes',
            self::SalesViewOwn => 'Consulter ses propres ventes',
            self::SalesViewAll => 'Consulter toutes les ventes',
            self::SalesCancel => 'Annuler une vente validée',
            self::DiscountsGrant => 'Accorder des remises au-delà du seuil caissier',
            self::ReturnsRequest => 'Enregistrer une demande de retour',
            self::ReturnsValidate => 'Valider les retours et remboursements',
            self::LossesDeclare => 'Déclarer une perte',
            self::LossesValidate => 'Valider les pertes',
            self::InventoriesManage => 'Gérer les inventaires',
            self::ExpensesManage => 'Gérer les dépenses',
            self::ReportsFinancial => 'Consulter les rapports financiers',
            self::UsersManage => 'Gérer les utilisateurs',
            self::SettingsManage => 'Modifier les paramètres',
            self::AuditView => 'Consulter le journal d’audit',
        };
    }
}
