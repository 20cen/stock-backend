<?php

namespace App\Services;

use App\Enums\LossReason;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Exceptions\BusinessException;
use App\Models\Article;
use App\Models\Location;
use App\Models\Loss;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockService;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pertes et produits endommagés (SFD-PER, RG-PER-01 à 04).
 *
 * Déclaration motivée ; le stock ne baisse qu'à la validation (TST-016). Validation directe si le déclarant
 * a le droit losses.validate, ou si la valeur au CMP ne dépasse pas le seuil paramétré (0 par défaut :
 * toutes les pertes passent par le propriétaire).
 */
class LossService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DocumentNumber $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{article_id: int, location_id: int, quantity: int|string, reason: string, comment?: string|null}  $data
     */
    public function declare(array $data, User $by): Loss
    {
        return DB::transaction(function () use ($data, $by) {
            $article = Article::find($data['article_id']) ?? throw new BusinessException('Article introuvable.');
            $location = Location::where('active', true)->find($data['location_id']) ?? throw new BusinessException('Emplacement introuvable ou désactivé.');
            $reason = LossReason::tryFrom((string) $data['reason']) ?? throw new BusinessException('Motif de perte invalide.');
            $comment = trim((string) ($data['comment'] ?? ''));
            if ($reason === LossReason::Other && mb_strlen($comment) < 3) {
                throw new BusinessException('Précisez le motif dans le commentaire.');
            }

            try {
                $quantity = Decimal::normalize((string) $data['quantity'], Decimal::QUANTITY_SCALE);
            } catch (InvalidArgumentException) {
                throw new BusinessException('Quantité invalide.');
            }
            if (bccomp($quantity, '0', 3) <= 0) {
                throw new BusinessException('La quantité perdue doit être positive.');
            }
            if (! $article->allow_decimal_quantity && ! Decimal::isInteger($quantity)) {
                throw new BusinessException("L’article « {$article->designation} » se compte en unités entières.");
            }

            // Contrôle immédiat du disponible (revérifié sous verrou à la validation).
            $available = StockBalance::where('article_id', $article->id)->where('location_id', $location->id)->value('quantity_on_hand') ?? '0.000';
            if (bccomp($quantity, $available, 3) > 0) {
                throw new BusinessException(sprintf(
                    'Stock insuffisant pour « %s » (%s) : %s disponible(s).',
                    $article->designation,
                    $location->name,
                    str_replace('.', ',', Decimal::trim($available)),
                ));
            }

            $value = $article->average_cost === null ? null : Decimal::round(bcmul($quantity, $article->average_cost, 8), 2);

            $loss = Loss::create([
                'reference' => $this->numbers->next('PER'),
                'article_id' => $article->id,
                'location_id' => $location->id,
                'quantity' => $quantity,
                'reason' => $reason,
                'comment' => $comment !== '' ? $comment : null,
                'estimated_value' => $value,
                'status' => Loss::PENDING,
                'declared_by' => $by->id,
                'declared_at' => now(),
            ]);
            $this->audit->log('loss.declared', $loss, after: ['quantity' => $quantity, 'reason' => $reason->value, 'value' => $value], reason: $comment ?: $reason->label(), userId: $by->id);

            if ($by->hasPermission(Permission::LossesValidate) || $this->belowLimit($value)) {
                $this->validate($loss, $by);
            }

            return $loss->refresh();
        });
    }

    public function validate(Loss $loss, User $by): Loss
    {
        return DB::transaction(function () use ($loss, $by) {
            $loss = Loss::lockForUpdate()->findOrFail($loss->id);
            if ($loss->status !== Loss::PENDING) {
                throw new BusinessException('Cette perte a déjà été traitée.');
            }

            $this->stock->apply(
                [StockChange::out($loss->article_id, $loss->location_id, MovementType::Loss, $loss->quantity)],
                $by,
                $loss,
                "Perte {$loss->reference} : {$loss->reason->label()}".($loss->comment ? " — {$loss->comment}" : ''),
            );
            $loss->update(['status' => Loss::VALIDATED, 'decided_by' => $by->id, 'decided_at' => now()]);
            $this->audit->log('loss.validated', $loss, after: ['quantity' => $loss->quantity], userId: $by->id);

            return $loss;
        });
    }

    public function reject(Loss $loss, User $by, string $reason): Loss
    {
        return DB::transaction(function () use ($loss, $by, $reason) {
            $loss = Loss::lockForUpdate()->findOrFail($loss->id);
            if ($loss->status !== Loss::PENDING) {
                throw new BusinessException('Cette perte a déjà été traitée.');
            }
            $loss->update(['status' => Loss::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'rejection_reason' => $reason]);
            $this->audit->log('loss.rejected', $loss, reason: $reason, userId: $by->id);

            return $loss;
        });
    }

    /** Valeur connue et inférieure ou égale au seuil d'auto-validation (seuil 0 : jamais). */
    private function belowLimit(?string $value): bool
    {
        $limit = (string) Setting::get(Setting::LOSS_AUTO_VALIDATE_LIMIT);

        return $value !== null && bccomp($limit, '0', 2) > 0 && bccomp($value, $limit, 2) <= 0;
    }
}
