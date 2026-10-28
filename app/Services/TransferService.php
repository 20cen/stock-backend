<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Exceptions\BusinessException;
use App\Exceptions\StockException;
use App\Models\Article;
use App\Models\Location;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Transferts entre emplacements (SFD-STK, RG-STK-04/05) : l'origine diminue, la destination augmente,
 * dans une seule transaction. Ce n'est pas une vente : aucun chiffre d'affaires, CMP inchangé.
 */
class TransferService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DocumentNumber $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{source_location_id: int, destination_location_id: int, notes?: string|null, lines: list<array{article_id: int, quantity: int|string}>}  $data
     */
    public function create(array $data, User $by): Transfer
    {
        $sourceId = (int) $data['source_location_id'];
        $destinationId = (int) $data['destination_location_id'];
        if ($sourceId === $destinationId) {
            throw new BusinessException('L’emplacement de destination doit être différent de l’origine.');
        }

        return DB::transaction(function () use ($data, $by, $sourceId, $destinationId) {
            $articles = Article::whereIn('id', array_column($data['lines'], 'article_id'))->get()->keyBy('id');
            $changes = [];
            $lines = [];

            foreach ($data['lines'] as $row) {
                $article = $articles->get((int) $row['article_id']) ?? throw new BusinessException('Article introuvable.');
                if (isset($lines[$article->id])) {
                    throw new BusinessException("L’article « {$article->designation} » figure deux fois : regroupez les quantités.");
                }
                // StockChange normalise et contrôle la quantité ; StockService vérifie le disponible et les décimales.
                $out = StockChange::out($article->id, $sourceId, MovementType::TransferOut, (string) $row['quantity']);
                $changes[] = $out;
                $quantity = Decimal::abs($out->quantity);
                $changes[] = StockChange::in($article->id, $destinationId, MovementType::TransferIn, $quantity);
                $lines[$article->id] = ['article_id' => $article->id, 'quantity' => $quantity];
            }

            $transfer = Transfer::create([
                'reference' => $this->numbers->next('TRF'),
                'source_location_id' => $sourceId,
                'destination_location_id' => $destinationId,
                'status' => 'validated',
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
                'validated_at' => now(),
                'validated_by' => $by->id,
            ]);
            $transfer->lines()->createMany(array_values($lines));

            $source = Location::find($sourceId);
            $destination = Location::find($destinationId);
            if (! $source || ! $destination) {
                throw new StockException('Emplacement introuvable.');
            }

            $this->stock->apply($changes, $by, $transfer, "Transfert {$transfer->reference} : {$source->name} → {$destination->name}");
            $this->audit->log('transfer.validated', $transfer, after: [
                'source' => $source->code,
                'destination' => $destination->code,
                'lines' => array_column($lines, 'quantity', 'article_id'),
            ], userId: $by->id);

            return $transfer;
        });
    }
}
