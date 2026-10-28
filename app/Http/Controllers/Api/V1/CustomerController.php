<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CustomerRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

/**
 * Clients (SFD-CLI). Identifier le client n'est pas obligatoire pour une vente au comptant.
 */
class CustomerController extends PartnerController
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function auditName(): string
    {
        return 'customer';
    }

    protected function searchable(): array
    {
        return ['name', 'phone', 'email'];
    }

    public function show(Customer $customer): PartnerResource
    {
        return $this->showModel($customer);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        return $this->storeModel($request);
    }

    public function update(CustomerRequest $request, Customer $customer): PartnerResource
    {
        return $this->updateModel($request, $customer);
    }
}
