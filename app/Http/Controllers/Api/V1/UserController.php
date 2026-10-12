<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Tenancy\CurrentCommunity;
use App\Users\UserChangePublisher;
use App\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de usuarios de la comunidad del administrador (CDU25 a CDU28).
 * El escritorio usa /sync para trabajar sin conexión; estas rutas sirven a clientes en línea.
 */
class UserController extends Controller
{
    public const PER_PAGE = 20;

    private const FIELDS = ['given_names', 'paternal_surname', 'maternal_surname', 'phone', 'address', 'email', 'role', 'password'];

    public function __construct(
        private readonly UserService $users,
        private readonly UserChangePublisher $publisher,
        private readonly CurrentCommunity $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
        ]);

        $page = User::query()
            ->where('community_id', $this->context->id())
            ->when($data['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('email', $like));
            })
            ->when(isset($data['active']), fn ($q) => $q->where('is_active', (bool) $data['active']))
            ->orderBy('name')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => UserService::present($u))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(UserService::present($this->users->find($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->users->create($request->user(), $request->only(self::FIELDS));
        $this->publisher->publish($user, 'create');

        return ApiResponse::ok(UserService::present($user->fresh()), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->users->update($request->user(), $this->users->find($id), $request->only(self::FIELDS));
        $this->publisher->publish($user, 'update');

        return ApiResponse::ok(UserService::present($user->fresh()));
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        $user = $this->users->activate($request->user(), $this->users->find($id));
        $this->publisher->publish($user, 'update');

        return ApiResponse::ok(UserService::present($user->fresh()));
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $user = $this->users->find($id);
        $notices = $this->users->deactivate($request->user(), $user);
        $this->publisher->publish($user, 'update');

        return response()->json(['data' => UserService::present($user->fresh()), 'notices' => $notices]);
    }
}
