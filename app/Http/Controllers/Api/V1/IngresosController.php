<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Ingresos\IngresosService;
use App\Models\CategoriaIngreso;
use App\Models\Ingreso;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingresos extras (M8). El escritorio usa /sync; estas rutas sirven a clientes en línea.
 * Un ingreso mal registrado se anula con POST /movements/ingresos/{id}/void.
 */
class IngresosController extends Controller
{
    public function __construct(private readonly IngresosService $service) {}

    /** Categorías con su saldo (lo que hay en cada fondo). */
    public function categorias(): JsonResponse
    {
        return ApiResponse::ok(CategoriaIngreso::orderBy('name')->get()->map(fn (CategoriaIngreso $c) => [
            ...IngresosService::present($c),
            'saldo_cents' => $c->saldoCents(),
        ])->all());
    }

    public function storeCategoria(Request $request): JsonResponse
    {
        $categoria = $this->service->createCategoria($request->user(), $request->only(['name', 'description']));
        $this->service->publish($categoria, 'create');

        return ApiResponse::ok(IngresosService::present($categoria->fresh()), 201);
    }

    public function updateCategoria(Request $request, string $id): JsonResponse
    {
        $categoria = $this->service->updateCategoria($request->user(), $this->service->findCategoria($id),
            $request->only(['name', 'description', 'is_active']));
        $this->service->publish($categoria, 'update');

        return ApiResponse::ok(IngresosService::present($categoria->fresh()));
    }

    /** Ingresos, el más reciente primero; filtros: categoria_id, desde, hasta (Y-m-d). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categoria_id' => ['nullable', 'uuid'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $rows = Ingreso::query()
            ->with('categoria')
            ->when($data['categoria_id'] ?? null, fn ($q, $id) => $q->where('categoria_id', $id))
            ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('received_on', '>=', $d))
            ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('received_on', '<=', $d))
            ->orderByDesc('received_on')->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return ApiResponse::ok($rows->map(fn (Ingreso $i) => [
            ...IngresosService::present($i),
            'categoria' => $i->categoria?->name,
        ])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $ingreso = $this->service->createIngreso($request->user(), $request->only(['categoria_id', 'amount_cents', 'received_on', 'note']));
        $this->service->publish($ingreso, 'create');

        return ApiResponse::ok(IngresosService::present($ingreso->fresh()), 201);
    }
}
