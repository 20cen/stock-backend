<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\CashSessionService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Paramètres (settings.manage) : identité de la boutique, règles de gestion configurables, modes de paiement.
 * Chaque modification est journalisée avec les valeurs avant/après.
 */
class SettingController extends Controller
{
    /** Champ de l'API → clé du paramètre. */
    private const FIELDS = [
        'shop_name' => Setting::SHOP_NAME,
        'shop_lines' => Setting::SHOP_LINES,
        'receipt_footer' => Setting::RECEIPT_FOOTER,
        'cashier_discount_limit' => Setting::CASHIER_DISCOUNT_LIMIT,
        'allow_credit' => Setting::ALLOW_CREDIT,
        'return_days' => Setting::RETURN_DAYS,
        'loss_auto_validate_limit' => Setting::LOSS_AUTO_VALIDATE_LIMIT,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->current(), 'payment_methods' => $this->methods()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:60'],
            'shop_lines' => ['present', 'array', 'max:5'],
            'shop_lines.*' => ['nullable', 'string', 'max:80'],
            'receipt_footer' => ['nullable', 'string', 'max:120'],
            'cashier_discount_limit' => ['required', 'regex:/^\d+(\.\d+)?$/', 'numeric', 'max:100'],
            'allow_credit' => ['required', 'boolean'],
            'return_days' => ['required', 'integer', 'min:0', 'max:365'],
            'loss_auto_validate_limit' => ['required', 'regex:/^\d+(\.\d+)?$/', 'numeric', 'max:999999999999'],
        ], [
            'cashier_discount_limit.regex' => 'Plafond de remise : nombre positif attendu.',
            'loss_auto_validate_limit.regex' => 'Seuil des pertes : montant positif attendu.',
            'shop_name.required' => 'Indiquez le nom de la boutique.',
            'shop_lines.max' => 'Cinq lignes au maximum sous le nom.',
            'cashier_discount_limit.max' => "Le plafond de remise ne peut dépasser 100\u{00A0}%.",
            'return_days.max' => 'Le délai de retour ne peut dépasser 365 jours.',
        ]);

        $values = [
            'shop_name' => trim($data['shop_name']),
            'shop_lines' => array_values(array_filter(array_map(fn ($l) => trim((string) $l), $data['shop_lines']), fn ($l) => $l !== '')),
            'receipt_footer' => trim((string) ($data['receipt_footer'] ?? '')),
            'cashier_discount_limit' => Decimal::trim(Decimal::round((string) $data['cashier_discount_limit'], 2)),
            'allow_credit' => (bool) $data['allow_credit'],
            'return_days' => (int) $data['return_days'],
            'loss_auto_validate_limit' => Decimal::trim(Decimal::round((string) $data['loss_auto_validate_limit'], 2)),
        ];

        $before = $this->current();
        $changed = array_filter($values, fn ($v, $field) => $v !== $before[$field], ARRAY_FILTER_USE_BOTH);

        if ($changed) {
            DB::transaction(function () use ($changed, $before, $request) {
                foreach ($changed as $field => $value) {
                    Setting::put(self::FIELDS[$field], $value, $request->user()->id);
                }
                $this->audit->log('settings.update', before: array_intersect_key($before, $changed), after: $changed);
            });
        }

        return response()->json(['data' => $this->current(), 'shop' => Setting::shop()]);
    }

    public function storeMethod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('payment_methods', 'name')],
        ], ['name.unique' => 'Ce mode de paiement existe déjà.']);

        $code = Str::slug($data['name'], '_') ?: 'mode';
        $base = $code;
        for ($i = 2; PaymentMethod::where('code', $code)->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        $method = PaymentMethod::create(['code' => $code, 'name' => trim($data['name']), 'active' => true]);
        $this->audit->log('payment_method.create', $method, after: $method->only(['code', 'name']));

        return response()->json(['data' => $this->methods()], 201);
    }

    public function updateMethod(Request $request, PaymentMethod $method): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('payment_methods', 'name')->ignore($method->id)],
            'active' => ['sometimes', 'boolean'],
        ], ['name.unique' => 'Ce mode de paiement existe déjà.']);

        if (array_key_exists('active', $data) && ! $data['active'] && $method->code === CashSessionService::CASH) {
            throw new BusinessException('Les espèces ne peuvent pas être désactivées : la caisse et le fond de caisse en dépendent.');
        }

        $before = $method->only(['name', 'active']);
        $method->update($data);
        $this->audit->log('payment_method.update', $method, before: $before, after: $method->only(['name', 'active']));

        return response()->json(['data' => $this->methods()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function current(): array
    {
        return [
            'shop_name' => (string) Setting::get(Setting::SHOP_NAME),
            'shop_lines' => array_values((array) Setting::get(Setting::SHOP_LINES)),
            'receipt_footer' => (string) Setting::get(Setting::RECEIPT_FOOTER),
            'cashier_discount_limit' => Decimal::trim((string) Setting::get(Setting::CASHIER_DISCOUNT_LIMIT)),
            'allow_credit' => (bool) Setting::get(Setting::ALLOW_CREDIT),
            'return_days' => (int) Setting::get(Setting::RETURN_DAYS),
            'loss_auto_validate_limit' => Decimal::trim((string) Setting::get(Setting::LOSS_AUTO_VALIDATE_LIMIT)),
        ];
    }

    /**
     * Tous les modes, actifs ou non, avec leur usage (un mode utilisé n'est jamais supprimé).
     *
     * @return list<array<string, mixed>>
     */
    private function methods(): array
    {
        $used = fn (string $table) => DB::table($table)->groupBy('payment_method_id')->selectRaw('payment_method_id, COUNT(*) AS n')->pluck('n', 'payment_method_id');
        $sales = $used('sale_payments');
        $others = [$used('purchase_payments'), $used('refunds'), $used('expenses')];

        return PaymentMethod::orderBy('id')->get()->map(fn (PaymentMethod $m) => [
            'id' => $m->id,
            'code' => $m->code,
            'name' => $m->name,
            'active' => $m->active,
            'locked' => $m->code === CashSessionService::CASH,
            'uses' => (int) ($sales[$m->id] ?? 0) + array_sum(array_map(fn ($c) => (int) ($c[$m->id] ?? 0), $others)),
        ])->all();
    }
}
