<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiptRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // RG-ACH-04 : l'emplacement de destination est obligatoire.
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'decimal:0,3', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.required' => 'Choisissez l’emplacement de destination.',
            'location_id.exists' => 'Cet emplacement n’existe pas.',
            'received_at.before_or_equal' => 'La date de réception ne peut pas être dans le futur.',
            'lines.required' => 'Indiquez au moins une quantité reçue.',
            'lines.*.quantity.decimal' => 'Quantité : 3 décimales au plus.',
            'lines.*.quantity.min' => 'Une quantité reçue ne peut pas être négative.',
        ];
    }
}
