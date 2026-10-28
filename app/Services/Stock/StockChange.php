<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Exceptions\StockException;
use App\Support\Decimal;
use InvalidArgumentException;

/**
 * Variation demandée pour un article dans un emplacement. Quantité signée (+ entrée, - sortie),
 * en chaîne décimale à 3 décimales. Le coût unitaire n'est pris en compte que pour les entrées.
 */
final readonly class StockChange
{
    public string $quantity;

    public ?string $unitCost;

    public function __construct(
        public int $articleId,
        public int $locationId,
        public MovementType $type,
        int|string $quantity,
        int|string|null $unitCost = null,
    ) {
        try {
            $this->quantity = Decimal::normalize($quantity, Decimal::QUANTITY_SCALE);
            $this->unitCost = $unitCost === null ? null : Decimal::normalize($unitCost, Decimal::COST_SCALE);
        } catch (InvalidArgumentException) {
            throw new StockException('Quantité ou coût invalide (3 décimales au plus pour une quantité, 4 pour un coût).');
        }
    }

    public static function in(int $articleId, int $locationId, MovementType $type, int|string $quantity, int|string|null $unitCost = null): self
    {
        return new self($articleId, $locationId, $type, Decimal::abs((string) $quantity), $unitCost);
    }

    public static function out(int $articleId, int $locationId, MovementType $type, int|string $quantity): self
    {
        return new self($articleId, $locationId, $type, '-'.Decimal::abs((string) $quantity));
    }

    public function isEntry(): bool
    {
        return bccomp($this->quantity, '0', Decimal::QUANTITY_SCALE) > 0;
    }
}
