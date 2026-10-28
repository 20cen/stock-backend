<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Article;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création (POST) et modification (PUT) d'un article (SFD-ART).
 */
class ArticleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $clean = [];

        // Référence interne normalisée en majuscules : « art-001 » et « ART-001 » sont la même référence (TST-004).
        if ($this->has('reference')) {
            $clean['reference'] = mb_strtoupper(trim((string) $this->input('reference')));
        }
        if ($this->has('barcode')) {
            $barcode = trim((string) $this->input('barcode'));
            $clean['barcode'] = $barcode === '' ? null : $barcode;
        }
        foreach (['designation', 'brand', 'model', 'unit'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = trim($this->input($field));
                $clean[$field] = $value === '' && $field !== 'designation' ? null : $value;
            }
        }

        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Article|null $article */
        $article = $this->route('article');
        $required = $article ? 'sometimes' : 'required';
        // Les prix sont réservés au propriétaire (prices.manage), même si l'accès au catalogue est élargi plus tard.
        $price = $this->user()?->hasPermission(Permission::PricesManage) ? [] : ['prohibited'];

        return [
            'reference' => [$required, 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9._\/-]*$/', Rule::unique('articles', 'reference')->ignore($article)],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('articles', 'barcode')->ignore($article)],
            'designation' => [$required, 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'allow_decimal_quantity' => ['sometimes', 'boolean'],
            'purchase_price_reference' => [...$price, 'nullable', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'sale_price' => [...$price, $article ? 'sometimes' : 'required', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'alert_threshold' => ['sometimes', 'decimal:0,3', 'min:0', 'max:1000000'],
            'serial_tracking' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference.required' => 'La référence est obligatoire.',
            'reference.regex' => 'La référence ne peut contenir que des lettres, chiffres, points, tirets, barres obliques et soulignés.',
            'reference.unique' => 'Cette référence est déjà utilisée par un autre article.',
            'barcode.regex' => 'Le code-barres ne peut contenir que des lettres, chiffres et tirets.',
            'barcode.unique' => 'Ce code-barres est déjà attribué à un autre article.',
            'designation.required' => 'La désignation est obligatoire.',
            'category_id.exists' => 'Cette catégorie n’existe pas.',
            'purchase_price_reference.decimal' => 'Le prix d’achat doit avoir au plus 2 décimales.',
            'purchase_price_reference.min' => 'Le prix d’achat ne peut pas être négatif.',
            'purchase_price_reference.prohibited' => 'Vous n’êtes pas autorisé à modifier les prix.',
            'sale_price.required' => 'Le prix de vente est obligatoire.',
            'sale_price.decimal' => 'Le prix de vente doit avoir au plus 2 décimales.',
            'sale_price.min' => 'Le prix de vente ne peut pas être négatif.',
            'sale_price.prohibited' => 'Vous n’êtes pas autorisé à modifier les prix.',
            'alert_threshold.decimal' => 'Le seuil d’alerte doit avoir au plus 3 décimales.',
            'alert_threshold.min' => 'Le seuil d’alerte ne peut pas être négatif.',
        ];
    }
}
