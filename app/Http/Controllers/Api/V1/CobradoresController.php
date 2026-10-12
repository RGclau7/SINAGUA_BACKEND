<?php

namespace App\Http\Controllers\Api\V1;

use App\Cobradores\AsignacionesService;
use App\Cobradores\DbAssignments;
use App\Http\Controllers\Controller;
use App\Models\Asignacion;
use App\Models\Familia;
use App\Models\Roles;
use App\Models\User;
use App\Models\Vivienda;
use App\Padron\PadronService;
use App\Support\ApiResponse;
use App\Tenancy\CurrentCommunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cobradores y asignación de viviendas (M6). El escritorio usa /sync; estas rutas sirven a
 * clientes en línea.
 */
class CobradoresController extends Controller
{
    public function __construct(
        private readonly AsignacionesService $service,
        private readonly DbAssignments $assignments,
        private readonly CurrentCommunity $context,
    ) {}

    /** Cobradores de la comunidad con cuántas viviendas tienen. */
    public function index(): JsonResponse
    {
        $cobradores = User::role(Roles::COBRADOR)->where('community_id', $this->context->id())->orderBy('name')->get();

        return ApiResponse::ok($cobradores->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'is_active' => (bool) $u->is_active,
            'viviendas' => $this->assignments->countAssigned($u),
        ])->all());
    }

    /** Asignaciones (vigentes por omisión; con historial=1 también las terminadas). */
    public function asignaciones(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cobrador_id' => ['nullable', 'uuid'],
            'vivienda_id' => ['nullable', 'uuid'],
            'historial' => ['nullable', 'boolean'],
        ]);

        $rows = Asignacion::query()
            ->with(['cobrador', 'vivienda'])
            ->when(! ($data['historial'] ?? false), fn ($q) => $q->vigentes())
            ->when($data['cobrador_id'] ?? null, fn ($q, $id) => $q->where('cobrador_id', $id))
            ->when($data['vivienda_id'] ?? null, fn ($q, $id) => $q->where('vivienda_id', $id))
            ->orderByDesc('starts_on')
            ->get();

        return ApiResponse::ok($rows->map(fn (Asignacion $a) => [
            ...AsignacionesService::present($a),
            'cobrador_name' => $a->cobrador?->name,
            'vivienda_label' => $a->vivienda?->label(),
        ])->all());
    }

    /** Asigna viviendas a un cobrador: {cobrador_id, vivienda_ids: [...]}. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cobrador_id' => ['required', 'uuid'],
            'vivienda_ids' => ['required', 'array', 'min:1', 'max:500'],
            'vivienda_ids.*' => ['uuid', 'distinct'],
        ]);
        $cobrador = $this->service->cobrador($data['cobrador_id']);

        $created = collect($data['vivienda_ids'])->map(function (string $viviendaId) use ($request, $cobrador) {
            $asignacion = $this->service->assign($request->user(), $cobrador, $viviendaId);
            $this->service->publish($asignacion, 'create');

            return AsignacionesService::present($asignacion->fresh());
        });

        return ApiResponse::ok($created->all(), 201);
    }

    public function end(Request $request, string $id): JsonResponse
    {
        $asignacion = $this->service->end($request->user(), $this->service->find($id));
        $this->service->publish($asignacion, 'update');

        return ApiResponse::ok(AsignacionesService::present($asignacion->fresh()));
    }

    /** Pasa todas las viviendas de un cobrador a otro: {from_cobrador_id, to_cobrador_id}. */
    public function reassign(Request $request): JsonResponse
    {
        $data = $request->validate(['from_cobrador_id' => ['required', 'uuid'], 'to_cobrador_id' => ['required', 'uuid']]);
        $from = User::where('community_id', $this->context->id())->findOrFail($data['from_cobrador_id']);
        $created = $this->service->reassignAll($request->user(), $from, $this->service->cobrador($data['to_cobrador_id']));
        foreach ($created as $asignacion) {
            $this->service->publish($asignacion, 'create');
        }

        return response()->json([
            'data' => array_map(fn ($a) => AsignacionesService::present($a->fresh()), $created),
            'notices' => [['code' => 'reassigned', 'message' => count($created).' vivienda(s) pasaron a otro cobrador.']],
        ]);
    }

    /** El cobrador consulta sus viviendas asignadas, con sus familias y titulares. */
    public function mine(Request $request): JsonResponse
    {
        $viviendas = Vivienda::whereIn('id', $this->assignments->viviendaIds($request->user()))->orderBy('address')->get();

        return ApiResponse::ok($viviendas->map(fn (Vivienda $v) => [
            ...PadronService::present($v),
            'label' => $v->label(),
            'familias' => $v->familias()->where('is_active', true)->with('head')->orderBy('name')->get()->map(fn (Familia $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'head_name' => $f->head?->name,
                'head_phone' => $f->head?->phone,
            ])->all(),
        ])->all());
    }
}
