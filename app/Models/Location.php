<?php

namespace App\Models;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
