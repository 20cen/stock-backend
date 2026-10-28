<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Modes de paiement initiaux ; la liste définitive est à confirmer avec le client.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'cash', 'name' => 'Espèces'],
            ['code' => 'mobile_money', 'name' => 'Mobile Money'],
            ['code' => 'bank_transfer', 'name' => 'Virement bancaire'],
            ['code' => 'cheque', 'name' => 'Chèque'],
            ['code' => 'card', 'name' => 'Carte bancaire'],
        ] as $method) {
            PaymentMethod::firstOrCreate(['code' => $method['code']], $method);
        }
    }
}
