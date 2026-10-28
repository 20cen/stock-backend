<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\SupplierRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

/**
 * Fournisseurs (SFD-FOU). Acquisitions et solde dû viendront avec le module Achats.
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

    public function store(SupplierRequest $request): JsonResponse
    {
        return $this->storeModel($request);
    }

    public function update(SupplierRequest $request, Supplier $supplier): PartnerResource
    {
        return $this->updateModel($request, $supplier);
    }
}
