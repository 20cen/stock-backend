<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['code' => 'MAG', 'name' => 'Magasin', 'type' => LocationType::Warehouse, 'is_sellable' => false],
            ['code' => 'BTQ', 'name' => 'Boutique', 'type' => LocationType::Shop, 'is_sellable' => true],
            // RG-RET-04 : les produits endommagés ne rejoignent pas le stock vendable.
            ['code' => 'DEF', 'name' => 'Retours / Défectueux', 'type' => LocationType::Defective, 'is_sellable' => false],
        ];

        foreach ($locations as $location) {
            Location::firstOrCreate(['code' => $location['code']], $location);
        }
    }
}
