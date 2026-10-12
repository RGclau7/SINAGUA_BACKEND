<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * Roles asignables y su matriz de permisos (SRS 2.3).
     */
    public function index(): JsonResponse
    {
        return ApiResponse::ok(collect(Permissions::ASSIGNABLE_ROLES)->map(fn (string $role) => [
            'name' => $role,
            'permissions' => Permissions::MATRIX[$role],
        ])->all());
    }
}
