<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\LoginService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ApiResponse;
use App\Tenancy\CurrentCommunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly CurrentCommunity $context,
    ) {}

    /**
     * POST /api/v1/auth/login {email, password, device_id?}
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'device_id' => ['nullable', 'uuid'],
        ]);

        [$user, $token] = $this->login->attempt($data['email'], $data['password'], $data['device_id'] ?? null, $request->ip());

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'idle_timeout_minutes' => config('sinagua.auth.idle_minutes'),
            'user' => $this->present($user),
        ]);
    }

    /**
     * GET /api/v1/auth/me — datos y rol vigentes del usuario.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->present($request->user()));
    }

    /**
     * POST /api/v1/auth/logout — invalida el token de esta sesión.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        $this->context->runAsSystem(fn () => AuditLog::record('auth.logout', [
            'community_id' => $user->community_id,
            'user_id' => $user->id,
            'entity' => 'users',
            'entity_id' => $user->id,
        ]));

        return ApiResponse::ok(['logged_out' => true]);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'panel' => $user->panel(),
            'community' => $user->community === null ? null : [
                'id' => $user->community->id,
                'name' => $user->community->name,
            ],
        ];
    }
}
