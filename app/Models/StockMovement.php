<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Mouvement de stock validé. Journal en ajout seul : une erreur se corrige par un mouvement inverse.
 *
 * @property-read string $reference
 */
#[Fillable([
    'article_id', 'location_id', 'type', 'quantity', 'balance_after', 'unit_cost',
    'source_type', 'source_id', 'reason', 'created_by',
])]
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un mouvement de stock ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Un mouvement de stock ne peut pas être supprimé.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    /**
     * Référence lisible, dérivée de l'identifiant (unique et immuable).
     */
    public function getReferenceAttribute(): string
    {
        return sprintf('MVT-%06d', $this->id);
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Document à l'origine du mouvement (réception, vente, transfert…).
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
