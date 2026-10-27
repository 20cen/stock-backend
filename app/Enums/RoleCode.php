<?php

namespace App\Enums;

enum RoleCode: string
{
    case Owner = 'owner';
    case Cashier = 'cashier';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propriétaire',
            self::Cashier => 'Caissier',
        };
    }

    /**
     * Matrice initiale des droits (01_Specifications_fonctionnelles_detaillees, §2).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Cashier => [
                Permission::DashboardView,
                Permission::CatalogView,
                Permission::StockView,
                Permission::CashOperate,
                Permission::SalesCreate,
                Permission::SalesViewOwn,
                Permission::ReturnsRequest,
                Permission::LossesDeclare,
            ],
        };
    }
}
