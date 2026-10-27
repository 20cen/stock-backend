<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');

        return response()->json([
            'user' => new UserResource($user),
            'permissions' => $user->permissionCodes(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        $this->audit->log('auth.logout', $request->user());

        return response()->json(['message' => 'Déconnexion effectuée.']);
    }
}
