<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StockException;
use App\Models\Article;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique de toute écriture de stock (réceptions, ventes, transferts, pertes, inventaires, corrections).
 *
 * Toutes les lignes sont appliquées dans une seule transaction : soit tout passe, soit rien.
 * Les soldes concernés sont verrouillés (SELECT … FOR UPDATE) dans un ordre fixe pour éviter les interblocages,
 * puis contrôlés : aucune sortie ne peut rendre un solde négatif (TST-009, TST-011).
 */
class StockService
{
    /**
     * @param  list<StockChange>  $changes
     * @return Collection<int, StockMovement> mouvements créés, dans l'ordre des lignes
     *
     * @throws StockException
     * @throws InsufficientStockException
     */
    public function apply(array $changes, User $by, ?Model $source = null, ?string $reason = null): Collection
    {
        if ($changes === []) {
            throw new StockException('Aucune ligne de stock à enregistrer.');
        }

        foreach ($changes as $change) {
            $this->assertQuantityMatchesType($change);
        }

        return DB::transaction(function () use ($changes, $by, $source, $reason) {
            $articles = Article::whereIn('id', array_unique(array_map(fn (StockChange $c) => $c->articleId, $changes)))->get()->keyBy('id');
            $locations = Location::whereIn('id', array_unique(array_map(fn (StockChange $c) => $c->locationId, $changes)))->get()->keyBy('id');

            foreach ($changes as $change) {
                $this->assertAllowed($change, $articles->get($change->articleId), $locations->get($change->locationId));
            }

            $balances = $this->lockBalances($changes);
            $movements = new Collection;

            foreach ($changes as $change) {
                $balance = $balances[$this->key($change->articleId, $change->locationId)];
                $newQuantity = $balance->quantity_on_hand + $change->quantity;

                if ($newQuantity < 0) {
                    throw new InsufficientStockException(
                        $articles[$change->articleId],
                        $locations[$change->locationId],
                        $balance->quantity_on_hand,
                        -$change->quantity,
                    );
                }

                $balance->quantity_on_hand = $newQuantity;

                $movements->push(StockMovement::create([
                    'article_id' => $change->articleId,
                    'location_id' => $change->locationId,
                    'type' => $change->type,
                    'quantity' => $change->quantity,
                    'balance_after' => $newQuantity,
                    'unit_cost' => $change->unitCost,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'reason' => $reason,
                    'created_by' => $by->id,
                ]));
            }

            foreach ($balances as $balance) {
                $balance->save();
            }

            return $movements;
        });
    }

    /**
     * Crée les soldes manquants puis les verrouille, toujours dans l'ordre (article, emplacement).
     *
     * @param  list<StockChange>  $changes
     * @return array<string, StockBalance>
     */
    private function lockBalances(array $changes): array
    {
        $pairs = [];
        foreach ($changes as $change) {
            $pairs[$this->key($change->articleId, $change->locationId)] = [$change->articleId, $change->locationId];
        }
        usort($pairs, fn (array $a, array $b) => $a <=> $b);

        $balances = [];
        foreach ($pairs as [$articleId, $locationId]) {
            StockBalance::insertOrIgnore([
                'article_id' => $articleId,
                'location_id' => $locationId,
                'quantity_on_hand' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balances[$this->key($articleId, $locationId)] = StockBalance::where('article_id', $articleId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->sole();
        }

        return $balances;
    }

    private function assertQuantityMatchesType(StockChange $change): void
    {
        $direction = $change->type->direction();

        if ($change->quantity === 0) {
            throw new StockException('La quantité d’un mouvement de stock ne peut pas être nulle.');
        }
        if (($direction === 1 && $change->quantity < 0) || ($direction === -1 && $change->quantity > 0)) {
            throw new StockException(sprintf('Quantité de signe incorrect pour un mouvement « %s ».', $change->type->label()));
        }
    }

    private function assertAllowed(StockChange $change, ?Article $article, ?Location $location): void
    {
        if (! $article) {
            throw new StockException('Article introuvable.');
        }
        if (! $location) {
            throw new StockException('Emplacement introuvable.');
        }
        if (! $location->active) {
            throw new StockException(sprintf('L’emplacement « %s » est désactivé.', $location->name));
        }
        if ($change->type === MovementType::Sale && ! $location->is_sellable) {
            throw new StockException(sprintf('L’emplacement « %s » n’est pas vendable.', $location->name));
        }
    }

    private function key(int $articleId, int $locationId): string
    {
        return $articleId.':'.$locationId;
    }
}
