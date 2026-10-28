<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'article_id' => ['required', 'integer', 'exists:articles,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'quantity' => [
                'required', 'decimal:0,3', 'between:-1000000,1000000',
                fn (string $attribute, mixed $value, Closure $fail) => is_numeric($value) && (float) $value == 0
                    ? $fail('La quantité ne peut pas être nulle.')
                    : null,
            ],
            'unit_cost' => ['nullable', 'decimal:0,4', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'article_id.required' => 'L’article est obligatoire.',
            'article_id.exists' => 'Cet article n’existe pas.',
            'location_id.required' => 'L’emplacement est obligatoire.',
            'location_id.exists' => 'Cet emplacement n’existe pas.',
            'quantity.required' => 'La quantité est obligatoire.',
            'quantity.decimal' => 'La quantité doit être un nombre avec au plus 3 décimales.',
            'quantity.between' => 'La quantité est hors limites.',
            'unit_cost.decimal' => 'Le coût unitaire doit avoir au plus 4 décimales.',
            'unit_cost.min' => 'Le coût unitaire ne peut pas être négatif.',
            'reason.required' => 'Le motif est obligatoire.',
            'reason.min' => 'Le motif doit contenir au moins 3 caractères.',
        ];
    }
}
