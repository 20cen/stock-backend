<?php

namespace App\Enums;

/**
 * Types de mouvement de stock. Le type impose le signe de la quantité (01_Specifications, SFD-STK).
 */
enum MovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case Sale = 'sale';
    case SaleCancellation = 'sale_cancellation';
    case CustomerReturn = 'customer_return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Loss = 'loss';
    case InventoryAdjustment = 'inventory_adjustment';
    case Adjustment = 'adjustment';

    /**
     * 1 : entrée seulement, -1 : sortie seulement, 0 : entrée ou sortie (correction).
     */
    public function direction(): int
    {
        return match ($this) {
            self::PurchaseReceipt, self::SaleCancellation, self::CustomerReturn, self::TransferIn => 1,
            self::Sale, self::TransferOut, self::Loss => -1,
            self::InventoryAdjustment, self::Adjustment => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'Réception d’achat',
            self::Sale => 'Vente',
            self::SaleCancellation => 'Annulation de vente',
            self::CustomerReturn => 'Retour client',
            self::TransferOut => 'Transfert (sortie)',
            self::TransferIn => 'Transfert (entrée)',
            self::Loss => 'Perte',
            self::InventoryAdjustment => 'Écart d’inventaire',
            self::Adjustment => 'Correction manuelle',
        };
    }
}
