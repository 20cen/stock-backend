<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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

    /** Délai de retour en jours après la vente ; au-delà, seul un responsable (returns.validate) l'accepte. */
    public const RETURN_DAYS = 'returns.max_days';

    /**
     * Valeur (au CMP) jusqu'à laquelle une perte déclarée est validée directement ;
     * au-delà, approbation du propriétaire (RG-PER-02). 0 : toutes les pertes sont soumises.
     */
    public const LOSS_AUTO_VALIDATE_LIMIT = 'losses.auto_validate_limit';

    /** Nom de la boutique (reçus, exports). */
    public const SHOP_NAME = 'shop.name';

    /** Lignes sous le nom sur les reçus : activité, adresse, téléphone, identifiant fiscal… */
    public const SHOP_LINES = 'shop.header_lines';

    /** Message en bas des reçus. */
    public const RECEIPT_FOOTER = 'shop.receipt_footer';

    /** Couleur principale de l'interface (boutons, liens, sélection), « #rrggbb ». */
    public const BRAND_PRIMARY = 'brand.primary_color';

    /** Couleur foncée : menu latéral, fond du pictogramme, en-têtes des documents PDF. */
    public const BRAND_DARK = 'brand.dark_color';

    /** Logo déposé : {path, mime, version} sur le disque « local », ou null (pictogramme par défaut). */
    public const BRAND_LOGO = 'brand.logo';

    public const DEFAULT_PRIMARY = '#4f46e5';

    public const DEFAULT_DARK = '#111833';

    private const DEFAULTS = [
        self::CASHIER_DISCOUNT_LIMIT => '5',
        self::ALLOW_CREDIT => false,
        self::RETURN_DAYS => 7,
        self::LOSS_AUTO_VALIDATE_LIMIT => '0',
        self::SHOP_LINES => ['Matériel informatique & fournitures de bureau'],
        self::RECEIPT_FOOTER => 'Merci de votre visite !',
        self::BRAND_PRIMARY => self::DEFAULT_PRIMARY,
        self::BRAND_DARK => self::DEFAULT_DARK,
        self::BRAND_LOGO => null,
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

        if ($setting) {
            return $setting->value;
        }

        return $key === self::SHOP_NAME ? config('app.shop_name', 'EMPIL') : (self::DEFAULTS[$key] ?? null);
    }

    /**
     * Identité de la boutique, transmise à l'application à la connexion (en-tête des reçus).
     *
     * @return array{name: string, lines: list<string>, footer: string}
     */
    public static function shop(): array
    {
        return [
            'name' => (string) self::get(self::SHOP_NAME),
            'lines' => array_values((array) self::get(self::SHOP_LINES)),
            'footer' => (string) self::get(self::RECEIPT_FOOTER),
        ];
    }

    /**
     * Identité visuelle, publique (écran de connexion) : nom, activité, couleurs, version du logo.
     *
     * @return array{name: string, tagline: string, primary_color: string, dark_color: string, logo_version: int|null}
     */
    public static function branding(): array
    {
        $shop = self::shop();
        $logo = self::get(self::BRAND_LOGO);

        return [
            'name' => $shop['name'],
            'tagline' => $shop['lines'][0] ?? '',
            'primary_color' => (string) self::get(self::BRAND_PRIMARY),
            'dark_color' => (string) self::get(self::BRAND_DARK),
            'logo_version' => is_array($logo) ? (int) $logo['version'] : null,
        ];
    }

    /**
     * Logo en data URI pour les PDF (Dompdf n'accède à aucune ressource extérieure), ou null.
     */
    public static function logoDataUri(): ?string
    {
        $logo = self::get(self::BRAND_LOGO);
        if (! is_array($logo) || ! Storage::disk('local')->exists($logo['path'])) {
            return null;
        }

        return 'data:'.$logo['mime'].';base64,'.base64_encode((string) Storage::disk('local')->get($logo['path']));
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
    }
}
