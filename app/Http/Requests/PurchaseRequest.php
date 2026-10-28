<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Forme des données d'une acquisition (création et modification du brouillon).
 * Les règles de gestion (article actif, quantités entières, remise ≤ montant…) sont dans PurchaseService.
 */
class PurchaseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'extra_costs' => ['nullable', 'decimal:0,2', 'min:0'],
            'confirm' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.article_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'lines.*.unit_price' => ['required', 'decimal:0,2', 'min:0'],
            'lines.*.discount' => ['nullable', 'decimal:0,2', 'min:0'],
            'initial_payment' => ['nullable', 'array'],
            'initial_payment.amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'initial_payment.payment_method_id' => ['required_with:initial_payment.amount', 'nullable', 'integer'],
            'initial_payment.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Le fournisseur est obligatoire.',
            'supplier_id.exists' => 'Ce fournisseur n’existe pas.',
            'purchase_date.required' => 'La date est obligatoire.',
            'purchase_date.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'extra_costs.decimal' => 'Les frais doivent avoir au plus 2 décimales.',
            'lines.required' => 'Ajoutez au moins une ligne d’article.',
            'lines.min' => 'Ajoutez au moins une ligne d’article.',
            'lines.*.quantity.required' => 'Quantité obligatoire.',
            'lines.*.quantity.decimal' => 'Quantité : 3 décimales au plus.',
            'lines.*.quantity.gt' => 'La quantité doit être positive.',
            'lines.*.unit_price.required' => 'Prix obligatoire.',
            'lines.*.unit_price.decimal' => 'Prix : 2 décimales au plus.',
            'lines.*.discount.decimal' => 'Remise : 2 décimales au plus.',
            'initial_payment.payment_method_id.required_with' => 'Choisissez le mode de paiement.',
        ];
    }
}
