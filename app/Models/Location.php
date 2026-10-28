<?php

namespace App\Models;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'type', 'is_sellable', 'active'])]
class Location extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_sellable' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Emplacements actifs dans un ordre stable (colonnes de stock), mémorisés le temps de la requête.
     *
     * @return Collection<int, self>
     */
    public static function activeInOrder(): Collection
    {
        return once(fn () => self::where('active', true)->orderBy('id')->get());
    }
}
