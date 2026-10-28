<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Paramètres de l'application (clé → valeur JSON). Les règles encore à valider avec le client
 * y sont configurables, avec une valeur par défaut prudente.
 */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    /** Plafond de remise du caissier, en % du sous-total ; au-delà : droit discounts.grant. */
    public const CASHIER_DISCOUNT_LIMIT = 'sales.cashier_discount_limit_percent';

    /** Vente à crédit (paiement partiel) autorisée ; un client est alors obligatoire. */
    public const ALLOW_CREDIT = 'sales.allow_credit';

    private const DEFAULTS = [
        self::CASHIER_DISCOUNT_LIMIT => '5',
        self::ALLOW_CREDIT => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function get(string $key): mixed
    {
        $setting = self::where('key', $key)->first();

        return $setting ? $setting->value : (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
    }
}
