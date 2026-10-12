<?php

namespace App\Http\Controllers\Api\V1;

use App\Cuotas\CuotasPublisher;
use App\Cuotas\CuotasService;
use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\Cuota;
use App\Models\Periodo;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cuotas y cargos (M5). El escritorio usa /sync; estas rutas sirven a clientes en línea.
 */
class CuotasController extends Controller
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly CuotasService $cuotas,
        private readonly CuotasPublisher $publisher,
    ) {}

    /** Historial de cuotas, la más reciente primero, con la vigente marcada. */
    public function index(): JsonResponse
    {
        $current = Cuota::forMonth((int) now()->format('Y'), (int) now()->format('n'));

        return ApiResponse::ok(Cuota::orderByDesc('valid_from')->get()->map(fn (Cuota $c) => [
            ...CuotasService::present($c),
            'is_current' => $c->is($current),
            'in_use' => Periodo::where('cuota_id', $c->id)->exists(),
        ])->all());
    }

    public function store(Request $request): JsonResponse
    {
        $cuota = $this->cuotas->createCuota($request->user(), $request->only(['amount_cents', 'valid_from', 'note']));
        $this->publisher->publish($cuota, 'create');

        return ApiResponse::ok(CuotasService::present($cuota->fresh()), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $cuota = $this->cuotas->updateCuota($request->user(), $this->cuotas->findCuota($id), $request->only(['amount_cents', 'valid_from', 'note']));
        $this->publisher->publish($cuota, 'update');

        return ApiResponse::ok(CuotasService::present($cuota->fresh()));
    }

    /** Meses generados con número de cargos, total y anulados. */
    public function periodos(): JsonResponse
    {
        $rows = Periodo::query()
            ->withCount(['cargos', 'cargos as anulados_count' => fn ($q) => $q->whereNotNull('voided_at')])
            ->withSum(['cargos as total_cents' => fn ($q) => $q->whereNull('voided_at')], 'amount_cents')
            ->orderByDesc('year')->orderByDesc('month')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($rows->items())->map(fn (Periodo $p) => [
                ...array_diff_key(CuotasService::present($p), ['cargo_ids' => true]),
                'label' => $p->label(),
                'cargos' => $p->cargos_count,
                'anulados' => $p->anulados_count,
                'total_cents' => (int) $p->total_cents,
            ])->all(),
            'meta' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()],
        ]);
    }

    /**
     * Asigna la cuota del mes y genera (o completa) sus cargos: {year, month, amount_cents?}.
     * Con amount_cents distinto a la cuota vigente, ese monto aplica desde ese mes.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer'], 'amount_cents' => ['nullable', 'integer']]);
        $result = $this->cuotas->assignMonth($request->user(), $data['year'], $data['month'], $data['amount_cents'] ?? null);
        if ($result['cuota'] !== null) {
            $this->publisher->publish($result['cuota'], 'update');
        }
        $this->publisher->publish($result['periodo'], 'update');

        return response()->json([
            'data' => CuotasService::present($result['periodo']->fresh()),
            'notices' => [['code' => 'cargos_generated', 'message' => CuotasService::generatedNotice($result)]],
        ], $result['created'] > 0 ? 201 : 200);
    }

    /** Multas, la más reciente primero, de 20 en 20. */
    public function multas(): JsonResponse
    {
        $rows = Cargo::where('concept', Cargo::MULTA)->with('familia.vivienda')->orderByDesc('created_at')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($rows->items())->map(fn (Cargo $c) => [
                ...CuotasService::present($c),
                'familia_name' => $c->familia?->name,
                'vivienda_label' => $c->familia?->vivienda?->label(),
            ])->all(),
            'meta' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()],
        ]);
    }

    /** Asigna una multa a una o varias familias: {familia_ids, description, amount_cents, year, month}. */
    public function storeMulta(Request $request): JsonResponse
    {
        $data = $request->validate(['familia_ids' => ['required', 'array', 'min:1', 'max:500'], 'familia_ids.*' => ['uuid', 'distinct']]);
        $fields = $request->only(['description', 'amount_cents', 'year', 'month']);

        $created = DB::transaction(fn () => collect($data['familia_ids'])
            ->map(fn (string $id) => $this->cuotas->createMulta($request->user(), $id, $fields))->all());
        foreach ($created as $cargo) {
            $this->publisher->publish($cargo, 'create');
        }

        return ApiResponse::ok(array_map(fn (Cargo $c) => CuotasService::present($c->fresh()), $created), 201);
    }

    /** Cargos de un mes, de 20 en 20, buscando por familia. */
    public function cargos(Request $request, string $id): JsonResponse
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;
        $periodo = Periodo::find($id) ?? abort(404);
        $rows = Cargo::query()
            ->where('periodo_id', $periodo->id)
            ->with('familia.vivienda')
            ->when($search, fn ($q, $s) => $q->whereHas('familia', fn ($f) => $f->whereLike('name', '%'.addcslashes($s, '%_\\').'%')))
            ->join('familias', 'familias.id', '=', 'cargos.familia_id')
            ->orderBy('familias.name')
            ->select('cargos.*')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($rows->items())->map(fn (Cargo $c) => [
                ...CuotasService::present($c),
                'familia_name' => $c->familia?->name,
                'vivienda_label' => $c->familia?->vivienda?->label(),
            ])->all(),
            'meta' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()],
        ]);
    }

    /** Estado de cuenta de una familia: sus cargos por mes (base de la cuadrícula de cobro). */
    public function familia(string $id): JsonResponse
    {
        return ApiResponse::ok(Cargo::where('familia_id', $id)->orderBy('year')->orderBy('month')->get()
            ->map(fn (Cargo $c) => [...CuotasService::present($c), 'label' => Periodo::monthLabel($c->year, $c->month)])->all());
    }
}
