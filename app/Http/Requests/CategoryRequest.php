<?php

namespace App\Http\Requests;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création (POST) et modification (PUT) d'une catégorie.
 */
class CategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Category|null $category */
        $category = $this->route('category');

        return [
            'name' => [
                $category ? 'sometimes' : 'required', 'string', 'max:100',
                // Unicité sans tenir compte de la casse : « Câbles » et « câbles » sont la même catégorie.
                function (string $attribute, mixed $value, Closure $fail) use ($category) {
                    $exists = Category::whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->when($category, fn ($q) => $q->whereKeyNot($category->id))
                        ->exists();
                    if ($exists) {
                        $fail('Cette catégorie existe déjà.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:1000'],
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
            'name.max' => 'Le nom ne doit pas dépasser 100 caractères.',
        ];
    }
}
