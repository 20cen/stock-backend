<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Catégories initiales de dépenses (cahier des charges §4.9).
 */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Transport', 'Livraison', 'Loyer', 'Électricité', 'Internet', 'Entretien', 'Réparation', 'Fournitures', 'Frais bancaires', 'Autres dépenses'] as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name]);
        }
    }
}
