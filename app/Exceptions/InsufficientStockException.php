<?php

namespace App\Exceptions;

use App\Models\Article;
use App\Models\Location;
use Illuminate\Http\JsonResponse;

/**
 * Une sortie rendrait le stock négatif (TST-009, TST-011).
 */
class InsufficientStockException extends StockException
{
    public function __construct(
        public readonly Article $article,
        public readonly Location $location,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(sprintf(
            'Stock insuffisant pour « %s » (%s) : %d disponible(s), %d demandé(s).',
            $article->designation,
            $location->name,
            $available,
            $requested,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'stock' => [
                'article_id' => $this->article->id,
                'location_id' => $this->location->id,
                'available' => $this->available,
                'requested' => $this->requested,
            ],
        ], 422);
    }
}
