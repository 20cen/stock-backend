<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    /** Partiellement reçue, puis clôturée : le reste ne sera jamais livré. */
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Ordered => 'Commandée',
            self::PartiallyReceived => 'Partiellement reçue',
            self::Received => 'Reçue',
            self::Closed => 'Clôturée',
            self::Cancelled => 'Annulée',
        };
    }

    /** Des marchandises peuvent encore être réceptionnées. */
    public function canReceive(): bool
    {
        return $this === self::Ordered || $this === self::PartiallyReceived;
    }

    /** Un paiement fournisseur peut être enregistré (acquisition validée et non annulée). */
    public function canPay(): bool
    {
        return ! in_array($this, [self::Draft, self::Cancelled], true);
    }
}
