<?php

namespace App\Services\Stock;

use App\Enums\MovementType;

/**
 * Variation demandée pour un article dans un emplacement. Quantité signée : + entrée, - sortie.
 */
final readonly class StockChange
{
    public function __construct(
        public int $articleId,
        public int $locationId,
        public MovementType $type,
        public int $quantity,
        public ?string $unitCost = null,
    ) {}

    public static function in(int $articleId, int $locationId, MovementType $type, int $quantity, ?string $unitCost = null): self
    {
        return new self($articleId, $locationId, $type, abs($quantity), $unitCost);
    }

    public static function out(int $articleId, int $locationId, MovementType $type, int $quantity, ?string $unitCost = null): self
    {
        return new self($articleId, $locationId, $type, -abs($quantity), $unitCost);
    }
}
