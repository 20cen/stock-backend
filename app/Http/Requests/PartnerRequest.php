<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Règles communes aux fiches fournisseur et client (création en POST, modification en PUT).
 */
abstract class PartnerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (array_keys($this->rules()) as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = trim($this->input($field));
                $clean[$field] = $value === '' ? null : $value;
            }
        }
        if (isset($clean['email'])) {
            $clean['email'] = mb_strtolower($clean['email']);
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+() .\/-]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
            'phone.regex' => 'Le téléphone ne peut contenir que des chiffres, espaces et les signes + ( ) . / -.',
            'email.email' => 'L’adresse électronique n’est pas valide.',
        ];
    }
}
