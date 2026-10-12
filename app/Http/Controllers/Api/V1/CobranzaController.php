<?php

namespace App\Http\Controllers\Api\V1;

use App\Cobradores\DbAssignments;
use App\Cobranza\CobranzaService;
use App\Cobranza\CobroAutomatico;
use App\Cuotas\CuotasService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Familia;
use App\Models\Pago;
use App\Models\Permissions;
use App\Models\Vivienda;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cobranza (M7): buscar el domicilio, ver la cuadrícula de cargos de cada familia y cobrar
 * completos los que elija. El escritorio usa /sync; estas rutas sirven a clientes en línea.
 */
class CobranzaController extends Controller
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly CobranzaService $cobranza,
        private readonly DbAssignments $assignments,
        private readonly CobroAutomatico $cobroAutomatico,
    ) {}

    /** Antes de mostrar adeudos: cuota del mes y multas vencidas al día. */
    private function upToDate(Request $request): void
    {
        if (($community = $request->user()->community) !== null) {
            $this->cobroAutomatico->runIfDue($community);
        }
    }

    /** Viviendas (todas para el administrador; las asignadas para el cobrador) con lo que deben. */
    public function buscar(Request $request): JsonResponse
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;
        $this->upToDate($request);
        $user = $request->user();
        $like = $search !== null ? '%'.addcslashes($search, '%_\\').'%' : null;

        $page = Vivienda::query()
            ->where('is_active', true)
            ->when(! $user->can(Permissions::COBROS_REGISTRAR), fn ($q) => $q->whereIn('id', $this->assignments->viviendaIds($user)))
            ->when($like, fn ($q) => $q->where(fn ($q) => $q->whereLike('address', $like)->orWhereLike('neighborhood', $like)
                ->orWhereHas('familias', fn ($f) => $f->whereLike('name', $like))))
            ->orderBy('address')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Vivienda $v) => [
                'id' => $v->id,
                'label' => $v->label(),
                'familias' => $v->familias()->where('is_active', true)->orderBy('name')->get()->map(fn (Familia $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'owed_cents' => (int) CobranzaService::unpaid()->where('familia_id', $f->id)->sum('amount_cents'),
                ])->all(),
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Cuadrícula de la familia: cada cuota y multa con su estado. */
    public function estado(Request $request, string $id): JsonResponse
    {
        $this->upToDate($request);

        return ApiResponse::ok($this->cobranza->estado($this->familiaFor($request, $id)));
    }

    /** Cobra completos los cargos elegidos: {familia_id, cargo_ids: [...]}. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'familia_id' => ['required', 'uuid'],
            'cargo_ids' => ['present', 'array', 'max:200'],
            'cargo_ids.*' => ['uuid', 'distinct'],
            'advance' => ['nullable', 'array', 'max:24'],
            'advance.*.year' => ['required', 'integer'],
            'advance.*.month' => ['required', 'integer'],
        ]);
        $advance = $data['advance'] ?? [];
        $cargoIds = [...$data['cargo_ids'], ...array_map(fn ($m) => CuotasService::cargoId(
            CuotasService::periodoId($request->user()->community_id, (int) $m['year'], (int) $m['month']), $data['familia_id']), $advance)];
        $pago = $this->cobranza->pay($request->user(), $data['familia_id'], $cargoIds, advance: $advance);
        $this->cobranza->publish($pago, 'create');

        return ApiResponse::ok(CobranzaService::present($pago->fresh()), 201);
    }

    /** Recibos, el más reciente primero (del cobrador, solo los suyos). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = Pago::query()
            ->with(['familia', 'vivienda'])
            ->when(! $user->can(Permissions::MOVIMIENTOS_ANULAR), fn ($q) => $q->where('cobrador_id', $user->id))
            ->when($request->query('familia_id'), fn ($q, $id) => $q->where('familia_id', $id))
            ->orderByDesc('paid_at')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Pago $p) => [
                ...CobranzaService::present($p),
                'familia_name' => $p->familia?->name,
                'vivienda_label' => $p->vivienda?->label(),
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    private function familiaFor(Request $request, string $id): Familia
    {
        $familia = Familia::find($id) ?? throw new ApiException('not_found', 'Familia no encontrada.', 404);
        if (! $request->user()->can(Permissions::COBROS_REGISTRAR) && ! in_array($familia->vivienda_id, $this->assignments->viviendaIds($request->user()), true)) {
            throw new ApiException('forbidden', 'Esta vivienda no está asignada a usted.', 403);
        }

        return $familia;
    }
}
