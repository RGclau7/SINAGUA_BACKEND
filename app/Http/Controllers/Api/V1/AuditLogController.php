<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditChain;
use App\Audit\AuditFeed;
use App\Audit\AuditLabels;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use App\Tenancy\CurrentCommunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CDU24: consulta de la bitácora de la comunidad (solo lectura), con filtros.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(AuditLabels::CATEGORIES)],
            'entity' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $page = AuditLog::query()
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->whereIn('action', AuditLabels::actionsIn($c)))
            ->when($data['entity'] ?? null, fn ($q, $e) => $q->where('entity', $e))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()->startOfDay()))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $l) => AuditFeed::present($l))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'message' => $page->total() === 0 ? 'No hay registros para los filtros elegidos.' : null,
            ],
        ]);
    }

    /**
     * Comprueba que nadie alteró la bitácora de la comunidad (cadena de huellas).
     */
    public function verify(CurrentCommunity $context): JsonResponse
    {
        return ApiResponse::ok(AuditChain::verify($context->id()));
    }
}
