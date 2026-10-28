<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transfert validé entre deux emplacements. Jamais modifié : une erreur se corrige par un transfert inverse.
 */
#[Fillable(['reference', 'source_location_id', 'destination_location_id', 'status', 'notes', 'created_by', 'validated_at', 'validated_by'])]
class Transfer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    /**
     * @return HasMany<TransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TransferLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
