<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PurchaseStatus;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Fournisseurs (SFD-FOU) et leur compte (acquisitions, payé, solde dû).
 */
class SupplierController extends PartnerController
{
    protected function model(): string
    {
        return Supplier::class;
    }

    protected function auditName(): string
    {
        return 'supplier';
    }

    protected function searchable(): array
    {
        return ['name', 'contact_name', 'phone', 'email', 'identifier'];
    }

    public function show(Supplier $supplier): PartnerResource
    {
        return $this->showModel($supplier);
    }

    /**
     * Compte fournisseur : montants des acquisitions validées (brouillons et annulées exclues), payé, solde dû,
     * et les acquisitions restant à payer.
     */
    public function account(Supplier $supplier): JsonResponse
    {
        $purchases = Purchase::where('supplier_id', $supplier->id)
            ->whereNotIn('status', [PurchaseStatus::Draft->value, PurchaseStatus::Cancelled->value]);

        $sums = (clone $purchases)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total - undelivered_amount), 0) AS total, COALESCE(SUM(paid_amount), 0) AS paid')
            ->first();
        $total = Decimal::round((string) $sums->total, 2);
        $paid = Decimal::round((string) $sums->paid, 2);

        $unpaid = (clone $purchases)
            ->whereRaw('paid_amount < total - undelivered_amount')
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Purchase $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'purchase_date' => $p->purchase_date->toDateString(),
                'status_label' => $p->status->label(),
                'net_total' => $p->net_total,
                'paid_amount' => $p->paid_amount,
                'balance_due' => $p->balance_due,
            ]);

        $lastPayment = PurchasePayment::whereNull('cancelled_at')
            ->whereIn('purchase_id', Purchase::where('supplier_id', $supplier->id)->select('id'))
            ->max('paid_at');

        return response()->json(['data' => [
            'purchases_count' => (int) $sums->n,
            'total' => $total,
            'paid' => $paid,
            'balance_due' => bcsub($total, $paid, 2),
            'last_payment_at' => $lastPayment ? Carbon::parse($lastPayment)->toIso8601String() : null,
            'unpaid_purchases' => $unpaid,
        ]]);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        return $this->storeModel($request);
    }

    public function update(SupplierRequest $request, Supplier $supplier): PartnerResource
    {
        return $this->updateModel($request, $supplier);
    }
}
