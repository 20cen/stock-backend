<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge(['username' => mb_strtolower(trim((string) $this->input('username')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($userId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'required', 'string', Password::min(8)->letters()->numbers()],
            'role_id' => ['sometimes', 'required', 'integer', 'exists:roles,id'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::frenchMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function frenchMessages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'username.required' => 'L’identifiant est obligatoire.',
            'username.alpha_dash' => 'L’identifiant ne peut contenir que des lettres, chiffres, tirets et soulignés.',
            'username.unique' => 'Cet identifiant est déjà utilisé.',
            'email.email' => 'L’adresse électronique n’est pas valide.',
            'email.unique' => 'Cette adresse électronique est déjà utilisée.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.letters' => 'Le mot de passe doit contenir au moins une lettre.',
            'password.numbers' => 'Le mot de passe doit contenir au moins un chiffre.',
            'role_id.required' => 'Le rôle est obligatoire.',
            'role_id.exists' => 'Ce rôle n’existe pas.',
        ];
    }
}
