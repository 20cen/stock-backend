<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,code,description')->orderBy('id')->get();

        return response()->json(['data' => $roles->map(fn (Role $role) => [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('code'),
        ])]);
    }
}
