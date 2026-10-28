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
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique de toute écriture de stock (réceptions, ventes, transferts, pertes, inventaires, corrections).
 *
 * Toutes les lignes sont appliquées dans une seule transaction : soit tout passe, soit rien.
 * Verrouillage (SELECT … FOR UPDATE) toujours dans le même ordre pour éviter les interblocages :
 * d'abord les articles, puis les soldes (article, emplacement). Aucune sortie ne peut rendre un solde négatif.
 *
 * Valorisation au coût moyen pondéré (CMP) par article, tous emplacements confondus :
 * - une entrée avec coût recalcule le CMP : (stock × CMP + quantité × coût) / (stock + quantité) ;
 * - une entrée sans coût (transfert, retour…) et toute sortie sont valorisées au CMP courant, qui ne change pas ;
 * - chaque mouvement garde son coût unitaire, pour calculer plus tard valeur du stock, marge et pertes.
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
            $articles = Article::whereIn('id', $this->ids($changes, 'articleId'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locations = Location::whereIn('id', $this->ids($changes, 'locationId'))->get()->keyBy('id');

            foreach ($changes as $change) {
                $this->assertAllowed($change, $articles->get($change->articleId), $locations->get($change->locationId));
            }

            $balances = $this->lockBalances($changes);
            $totals = $this->totalsByArticle($articles->keys()->all());
            $movements = new Collection;

            foreach ($changes as $change) {
                $article = $articles[$change->articleId];
                $balance = $balances[$this->key($change->articleId, $change->locationId)];
                $newQuantity = bcadd($balance->quantity_on_hand, $change->quantity, Decimal::QUANTITY_SCALE);

                if (bccomp($newQuantity, '0', Decimal::QUANTITY_SCALE) < 0) {
                    throw new InsufficientStockException(
                        $article,
                        $locations[$change->locationId],
                        $balance->quantity_on_hand,
                        Decimal::abs($change->quantity),
                    );
                }

                $unitCost = $this->valuate($article, $change, $totals[$article->id]);

                $balance->quantity_on_hand = $newQuantity;
                $totals[$article->id] = bcadd($totals[$article->id], $change->quantity, Decimal::QUANTITY_SCALE);

                $movements->push(StockMovement::create([
                    'article_id' => $change->articleId,
                    'location_id' => $change->locationId,
                    'type' => $change->type,
                    'quantity' => $change->quantity,
                    'balance_after' => $newQuantity,
                    'unit_cost' => $unitCost,
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'reason' => $reason,
                    'created_by' => $by->id,
                ]));
            }

            foreach ($balances as $balance) {
                $balance->save();
            }
            foreach ($articles as $article) {
                $article->save();
            }

            return $movements;
        });
    }

    /**
     * Coût unitaire du mouvement ; met à jour le CMP de l'article pour une entrée valorisée.
     */
    private function valuate(Article $article, StockChange $change, string $stockBefore): ?string
    {
        if (! $change->isEntry() || $change->unitCost === null) {
            return $article->average_cost;
        }

        if ($article->average_cost === null || bccomp($stockBefore, '0', Decimal::QUANTITY_SCALE) <= 0) {
            $article->average_cost = $change->unitCost;
        } else {
            $scale = Decimal::COST_SCALE + 6;
            $value = bcadd(
                bcmul($stockBefore, $article->average_cost, $scale),
                bcmul($change->quantity, $change->unitCost, $scale),
                $scale,
            );
            $article->average_cost = Decimal::round(
                bcdiv($value, bcadd($stockBefore, $change->quantity, $scale), $scale),
                Decimal::COST_SCALE,
            );
        }

        return $change->unitCost;
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

    /**
     * Stock total de chaque article, tous emplacements confondus (base du CMP).
     * Fiable car toute écriture de stock verrouille d'abord l'article.
     *
     * @param  list<int>  $articleIds
     * @return array<int, string>
     */
    private function totalsByArticle(array $articleIds): array
    {
        $sums = StockBalance::whereIn('article_id', $articleIds)
            ->groupBy('article_id')
            ->selectRaw('article_id, SUM(quantity_on_hand) AS total')
            ->pluck('total', 'article_id');

        $totals = [];
        foreach ($articleIds as $id) {
            $totals[$id] = Decimal::round((string) ($sums[$id] ?? '0'), Decimal::QUANTITY_SCALE);
        }

        return $totals;
    }

    private function assertQuantityMatchesType(StockChange $change): void
    {
        $direction = $change->type->direction();

        if (Decimal::isZero($change->quantity)) {
            throw new StockException('La quantité d’un mouvement de stock ne peut pas être nulle.');
        }
        if (($direction === 1 && ! $change->isEntry()) || ($direction === -1 && $change->isEntry())) {
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
        if (! $article->allow_decimal_quantity && ! Decimal::isInteger($change->quantity)) {
            throw new StockException(sprintf('L’article « %s » se compte en unités entières.', $article->designation));
        }
        if (! $location->active) {
            throw new StockException(sprintf('L’emplacement « %s » est désactivé.', $location->name));
        }
        if ($change->type === MovementType::Sale && ! $location->is_sellable) {
            throw new StockException(sprintf('L’emplacement « %s » n’est pas vendable.', $location->name));
        }
    }

    /**
     * @param  list<StockChange>  $changes
     * @return list<int>
     */
    private function ids(array $changes, string $property): array
    {
        return array_values(array_unique(array_map(fn (StockChange $c) => $c->{$property}, $changes)));
    }

    private function key(int $articleId, int $locationId): string
    {
        return $articleId.':'.$locationId;
    }
}
