<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Closure;

class SupplierRequest extends PartnerRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Supplier|null $supplier */
        $supplier = $this->route('supplier');

        $rules = $this->commonRules(creating: $supplier === null);
        // Deux fournisseurs du même nom prêteraient à confusion sur les achats.
        $rules['name'][] = function (string $attribute, mixed $value, Closure $fail) use ($supplier) {
            $exists = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                ->when($supplier, fn ($q) => $q->whereKeyNot($supplier->id))
                ->exists();
            if ($exists) {
                $fail('Un fournisseur porte déjà ce nom.');
            }
        };

        return $rules + [
            'contact_name' => ['nullable', 'string', 'max:255'],
            'identifier' => ['nullable', 'string', 'max:100'],
        ];
    }
}
