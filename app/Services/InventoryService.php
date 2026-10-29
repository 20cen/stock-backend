<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\BusinessException;
use App\Models\Article;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryLine;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inventaires physiques (SFD-INV, RG-INV-01 à 04).
 *
 * - À l'ouverture, la quantité théorique de chaque article actif est figée (RG-INV-02).
 * - Les comptages n'ont aucun effet sur le stock (RG-INV-03, TST-017).
 * - À la validation, chaque écart (compté − théorique figé) devient un mouvement « écart d'inventaire »
 *   (RG-INV-04, TST-018). Appliquer l'écart plutôt que forcer le compté garde justes les ventes ou
 *   réceptions faites pendant le comptage. Les articles non comptés sont ignorés.
 */
class InventoryService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DocumentNumber $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function create(int $locationId, ?int $categoryId, ?string $notes, User $by): Inventory
    {
        $location = Location::where('active', true)->find($locationId) ?? throw new BusinessException('Emplacement introuvable ou désactivé.');
        if ($categoryId !== null && ! Category::whereKey($categoryId)->exists()) {
            throw new BusinessException('Catégorie introuvable.');
        }

        try {
            return DB::transaction(function () use ($location, $categoryId, $notes, $by) {
                $inventory = Inventory::create([
                    'reference' => $this->numbers->next('INV'),
                    'location_id' => $location->id,
                    'category_id' => $categoryId,
                    'status' => Inventory::IN_PROGRESS,
                    'notes' => $notes,
                    'created_by' => $by->id,
                    'started_at' => now(),
                ]);

                // Instantané des quantités théoriques de tous les articles actifs du périmètre.
                $balances = StockBalance::where('location_id', $location->id)->pluck('quantity_on_hand', 'article_id');
                $now = now();
                Article::where('active', true)
                    ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
                    ->orderBy('id')
                    ->chunk(500, function ($articles) use ($inventory, $balances, $now) {
                        InventoryLine::insert($articles->map(fn (Article $a) => [
                            'inventory_id' => $inventory->id,
                            'article_id' => $a->id,
                            'theoretical_quantity' => $balances[$a->id] ?? '0.000',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all());
                    });

                $this->audit->log('inventory.started', $inventory, after: ['location' => $location->code, 'lines' => $inventory->lines()->count()], userId: $by->id);

                return $inventory;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessException("Un inventaire est déjà en cours pour « {$location->name} ».");
        }
    }

    /**
     * Enregistre des comptages (partiels). counted_quantity null : remet la ligne « non comptée ».
     *
     * @param  list<array{article_id: int, counted_quantity: int|string|null, reason?: string|null}>  $rows
     */
    public function saveCounts(Inventory $inventory, array $rows, User $by): Inventory
    {
        return DB::transaction(function () use ($inventory, $rows, $by) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventory->id);
            if ($inventory->status !== Inventory::IN_PROGRESS) {
                throw new BusinessException('Cet inventaire est clôturé : les comptages ne sont plus modifiables.');
            }

            $lines = $inventory->lines()->get()->keyBy('article_id');
            $articles = Article::whereIn('id', array_column($rows, 'article_id'))->get()->keyBy('id');

            foreach ($rows as $row) {
                $article = $articles->get((int) $row['article_id']) ?? throw new BusinessException('Article introuvable.');
                $counted = $this->counted($row['counted_quantity'] ?? null, $article);

                // Article absent de l'instantané (créé ou réactivé depuis) : sa quantité théorique est figée maintenant.
                $line = $lines->get($article->id) ?? $inventory->lines()->create([
                    'article_id' => $article->id,
                    'theoretical_quantity' => StockBalance::where('article_id', $article->id)->where('location_id', $inventory->location_id)->value('quantity_on_hand') ?? '0.000',
                ]);

                $line->update([
                    'counted_quantity' => $counted,
                    'reason' => isset($row['reason']) && trim((string) $row['reason']) !== '' ? trim((string) $row['reason']) : null,
                    'counted_by' => $counted === null ? null : $by->id,
                    'counted_at' => $counted === null ? null : now(),
                ]);
            }

            return $inventory;
        });
    }

    public function validate(Inventory $inventory, User $by): Inventory
    {
        return DB::transaction(function () use ($inventory, $by) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventory->id);
            if ($inventory->status !== Inventory::IN_PROGRESS) {
                throw new BusinessException('Cet inventaire est déjà clôturé.');
            }

            $counted = $inventory->lines()->whereNotNull('counted_quantity')->get();
            if ($counted->isEmpty()) {
                throw new BusinessException('Aucun article n’a été compté.');
            }

            $changes = $counted
                ->filter(fn (InventoryLine $l) => ! Decimal::isZero($l->variance))
                ->map(fn (InventoryLine $l) => new StockChange($l->article_id, $inventory->location_id, MovementType::InventoryAdjustment, $l->variance))
                ->values()
                ->all();

            if ($changes !== []) {
                $this->stock->apply($changes, $by, $inventory, "Inventaire {$inventory->reference}");
            }

            $inventory->update(['status' => Inventory::VALIDATED, 'closed_by' => $by->id, 'closed_at' => now()]);
            $this->audit->log('inventory.validated', $inventory, after: [
                'counted' => $counted->count(),
                'adjustments' => count($changes),
                'not_counted' => $inventory->lines()->whereNull('counted_quantity')->count(),
            ], userId: $by->id);

            return $inventory;
        });
    }

    public function cancel(Inventory $inventory, User $by, string $reason): Inventory
    {
        return DB::transaction(function () use ($inventory, $by, $reason) {
            $inventory = Inventory::lockForUpdate()->findOrFail($inventory->id);
            if ($inventory->status !== Inventory::IN_PROGRESS) {
                throw new BusinessException('Cet inventaire est déjà clôturé.');
            }
            $inventory->update(['status' => Inventory::CANCELLED, 'closed_by' => $by->id, 'closed_at' => now(), 'cancel_reason' => $reason]);
            $this->audit->log('inventory.cancelled', $inventory, reason: $reason, userId: $by->id);

            return $inventory;
        });
    }

    private function counted(int|string|float|null $value, Article $article): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $quantity = Decimal::normalize((string) $value, Decimal::QUANTITY_SCALE);
        } catch (InvalidArgumentException) {
            throw new BusinessException("Quantité comptée invalide pour « {$article->designation} ».");
        }
        if (bccomp($quantity, '0', 3) < 0) {
            throw new BusinessException("La quantité comptée de « {$article->designation} » ne peut pas être négative.");
        }
        if (! $article->allow_decimal_quantity && ! Decimal::isInteger($quantity)) {
            throw new BusinessException("L’article « {$article->designation} » se compte en unités entières.");
        }

        return $quantity;
    }
}
