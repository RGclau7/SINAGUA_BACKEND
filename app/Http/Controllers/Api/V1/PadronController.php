<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Familia;
use App\Models\Habitante;
use App\Models\Vivienda;
use App\Padron\PadronPublisher;
use App\Padron\PadronService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Padrón de la comunidad (CDU3 a CDU5): viviendas, familias y habitantes.
 * El escritorio usa /sync para trabajar sin conexión; estas rutas sirven a clientes en línea.
 * {entity} es viviendas, familias o habitantes.
 */
class PadronController extends Controller
{
    public const PER_PAGE = 20;

    private const FIELDS = [
        PadronService::VIVIENDAS => ['address', 'neighborhood', 'reference'],
        PadronService::FAMILIAS => ['vivienda_id', 'name', 'head_id', 'cuota_especial_cents'],
        PadronService::HABITANTES => ['familia_id', 'given_names', 'paternal_surname', 'maternal_surname', 'phone'],
    ];

    public function __construct(
        private readonly PadronService $padron,
        private readonly PadronPublisher $publisher,
    ) {}

    /**
     * Búsqueda paginada de 20 en 20. Por omisión solo los activos (status=activos|baja|todos).
     */
    public function index(Request $request, string $entity): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['activos', 'baja', 'todos'])],
            'vivienda_id' => ['nullable', 'uuid'],
            'familia_id' => ['nullable', 'uuid'],
        ]);
        $status = $data['status'] ?? 'activos';
        $like = isset($data['search']) ? '%'.addcslashes($data['search'], '%_\\').'%' : null;

        $query = match ($entity) {
            PadronService::VIVIENDAS => Vivienda::query()
                ->withCount(['familias' => fn ($q) => $q->where('is_active', true)])
                ->when($like, fn ($q) => $q->where(fn ($q) => $q->whereLike('address', $like)->orWhereLike('neighborhood', $like)
                    ->orWhereLike('reference', $like)->orWhereHas('familias', fn ($f) => $f->whereLike('name', $like))))
                ->orderBy('address'),
            PadronService::FAMILIAS => Familia::query()
                ->with(['vivienda', 'head'])
                ->withCount(['habitantes' => fn ($q) => $q->where('is_active', true)])
                ->when($data['vivienda_id'] ?? null, fn ($q, $id) => $q->where('vivienda_id', $id))
                ->when($like, fn ($q) => $q->where(fn ($q) => $q->whereLike('name', $like)
                    ->orWhereHas('habitantes', fn ($h) => $h->whereLike('name', $like))
                    ->orWhereHas('vivienda', fn ($v) => $v->whereLike('address', $like)->orWhereLike('neighborhood', $like))))
                ->orderBy('name'),
            PadronService::HABITANTES => Habitante::query()
                ->with('familia.vivienda')
                ->when($data['familia_id'] ?? null, fn ($q, $id) => $q->where('familia_id', $id))
                ->when($like, fn ($q) => $q->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('phone', $like)))
                ->orderBy('name'),
        };
        $query->when($status !== 'todos', fn (Builder $q) => $q->where('is_active', $status === 'activos'));

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Model $r) => $this->summary($r))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'message' => $page->total() === 0 ? 'No hay registros que coincidan con la búsqueda.' : null,
            ],
        ]);
    }

    public function show(string $entity, string $id): JsonResponse
    {
        $record = $this->padron->find($entity, $id);
        $data = $this->summary($record);

        if ($record instanceof Vivienda) {
            $data['familias'] = $record->familias()->orderBy('name')->get()->map(fn ($f) => PadronService::present($f))->all();
        } elseif ($record instanceof Familia) {
            $data['habitantes'] = $record->habitantes()->orderBy('name')->get()->map(fn ($h) => PadronService::present($h))->all();
        }

        return ApiResponse::ok($data);
    }

    /**
     * CDU3: registra la familia con su titular y, si hace falta, su vivienda.
     * Si el domicilio ya tiene familias responde 409 address_has_family; con
     * confirm_shared_address=true la registra en el mismo domicilio.
     */
    public function register(Request $request): JsonResponse
    {
        $result = $this->padron->register($request->user(), $request->only(['vivienda_id', 'vivienda', 'familia', 'titular', 'confirm_shared_address']));

        if ($result['vivienda_created']) {
            $this->publisher->publish($result['vivienda'], 'create');
        }
        $this->publisher->publish($result['familia'], 'create');
        $this->publisher->publish($result['titular'], 'create');

        return ApiResponse::ok([
            'vivienda' => PadronService::present($result['vivienda']->fresh()),
            'familia' => PadronService::present($result['familia']->fresh()),
            'titular' => PadronService::present($result['titular']->fresh()),
        ], 201);
    }

    public function store(Request $request, string $entity): JsonResponse
    {
        $fields = $request->only(self::FIELDS[$entity]);
        $record = match ($entity) {
            PadronService::VIVIENDAS => $this->padron->createVivienda($request->user(), $fields),
            PadronService::FAMILIAS => $this->padron->createFamilia($request->user(), $fields),
            PadronService::HABITANTES => $this->padron->createHabitante($request->user(), $fields),
        };
        $this->publisher->publish($record, 'create');

        return ApiResponse::ok(PadronService::present($record->fresh()), 201);
    }

    public function update(Request $request, string $entity, string $id): JsonResponse
    {
        $record = $this->padron->find($entity, $id);
        $fields = $request->only(self::FIELDS[$entity]);
        $record = match ($entity) {
            PadronService::VIVIENDAS => $this->padron->updateVivienda($request->user(), $record, $fields),
            PadronService::FAMILIAS => $this->padron->updateFamilia($request->user(), $record, $fields),
            PadronService::HABITANTES => $this->padron->updateHabitante($request->user(), $record, $fields),
        };
        $this->publisher->publish($record, 'update');

        return ApiResponse::ok(PadronService::present($record->fresh()));
    }

    /**
     * CDU5: baja lógica con motivo opcional. Con adeudo responde 409 has_debt (con el monto);
     * con confirm_debt=true la hace y avisa que el adeudo se conserva.
     */
    public function deactivate(Request $request, string $entity, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string'],
            'confirm_debt' => ['nullable', 'boolean'],
        ]);
        $record = $this->padron->find($entity, $id);
        $notices = $this->padron->deactivate($request->user(), $record, $data['reason'] ?? null, (bool) ($data['confirm_debt'] ?? false));
        $this->publisher->publish($record, 'update');

        return response()->json(['data' => PadronService::present($record->fresh()), 'notices' => $notices]);
    }

    public function activate(Request $request, string $entity, string $id): JsonResponse
    {
        $record = $this->padron->activate($request->user(), $this->padron->find($entity, $id));
        $this->publisher->publish($record, 'update');

        return ApiResponse::ok(PadronService::present($record->fresh()));
    }

    /** Forma de lista: el registro más lo necesario para mostrarlo sin otra consulta. */
    private function summary(Model $record): array
    {
        $data = PadronService::present($record);

        return match (true) {
            $record instanceof Vivienda => [...$data,
                'label' => $record->label(),
                'familias_activas' => $record->familias_count ?? $record->familias()->where('is_active', true)->count(),
            ],
            $record instanceof Familia => [...$data,
                'vivienda_label' => $record->vivienda?->label(),
                'head_name' => $record->head?->name,
                'integrantes_activos' => $record->habitantes_count ?? $record->habitantes()->where('is_active', true)->count(),
            ],
            $record instanceof Habitante => [...$data,
                'familia_name' => $record->familia?->name,
                'vivienda_label' => $record->familia?->vivienda?->label(),
                'is_head' => $record->familia?->head_id === $record->id,
            ],
        };
    }
}
