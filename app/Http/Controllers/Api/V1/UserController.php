<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::with('role')->orderBy('name')->get());
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->validated());
            $this->audit->log('user.created', $user, after: Arr::except($request->validated(), 'password'));

            return $user;
        });

        return (new UserResource($user->load('role')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource|JsonResponse
    {
        $data = $request->validated();

        $deactivates = ($data['active'] ?? true) === false;
        $changesRole = isset($data['role_id']) && $data['role_id'] !== $user->role_id;

        if ($user->is($request->user()) && ($deactivates || $changesRole)) {
            return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte ni changer votre rôle.'], 422);
        }

        DB::transaction(function () use ($user, $data) {
            $before = Arr::only($user->toArray(), ['name', 'username', 'email', 'role_id', 'active']);
            $user->update($data);

            // Désactivation ou changement de mot de passe/rôle : on ferme toutes les sessions ouvertes.
            if (! $user->active || isset($data['password']) || $user->wasChanged('role_id')) {
                $user->tokens()->delete();
            }

            $after = Arr::except($data, 'password') + (isset($data['password']) ? ['password' => '(modifié)'] : []);
            $this->audit->log('user.updated', $user, before: $before, after: $after);
        });

        return new UserResource($user->load('role'));
    }
}
