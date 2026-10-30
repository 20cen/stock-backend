<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::with('role')->where('username', mb_strtolower($request->string('username')))->first();

        // Même message que l'identifiant soit inconnu ou le mot de passe faux (TST-002).
        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            $this->audit->log('auth.login_failed', after: ['username' => $request->string('username')->toString()]);

            return response()->json(['message' => 'Identifiant ou mot de passe incorrect.'], 422);
        }

        if (! $user->active) {
            $this->audit->log('auth.login_refused_inactive', $user, userId: $user->id);

            return response()->json(['message' => 'Ce compte est désactivé.'], 403);
        }

        $token = $user->createToken($request->string('device_name'))->plainTextToken;
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', $user, after: ['device' => $request->string('device_name')->toString()], userId: $user->id);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'permissions' => $user->permissionCodes(),
            'shop' => Setting::shop(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');

        return response()->json([
            'user' => new UserResource($user),
            'permissions' => $user->permissionCodes(),
            'shop' => Setting::shop(),
        ]);
    }

    /**
     * « Mon compte » : chacun change son propre mot de passe. Les autres appareils sont déconnectés.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.required' => 'Saisissez votre mot de passe actuel.',
            'password.confirmed' => 'Les deux saisies du nouveau mot de passe ne sont pas identiques.',
            'password.different' => 'Le nouveau mot de passe doit être différent de l’actuel.',
        ] + UpdateUserRequest::frenchMessages());

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            $this->audit->log('auth.password_change_failed', $user);
            throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->update(['password' => $data['password']]);
            $current = $user->currentAccessToken();
            $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
            $this->audit->log('auth.password_changed', $user);
        });

        return response()->json(['message' => 'Mot de passe modifié. Vos autres appareils ont été déconnectés.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $this->audit->log('auth.logout', $request->user());

        return response()->json(['message' => 'Déconnexion effectuée.']);
    }
}
