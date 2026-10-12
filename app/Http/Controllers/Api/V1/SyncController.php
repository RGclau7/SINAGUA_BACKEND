<?php

namespace App\Http\Controllers\Api\V1;

use App\Cobranza\CobroAutomatico;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\ApiResponse;
use App\Sync\Mutation;
use App\Sync\SyncService;
use App\Tenancy\CurrentCommunity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SyncController extends Controller
{
    public const MAX_BATCH = 500;

    public function __construct(
        private readonly SyncService $sync,
        private readonly CurrentCommunity $context,
        private readonly CobroAutomatico $cobroAutomatico,
    ) {}

    /**
     * POST /api/v1/sync/push — recibe la cola de salida de un equipo.
     */
    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'uuid'],
            'mutations' => ['present', 'array', 'max:'.self::MAX_BATCH],
            'mutations.*.mutation_id' => ['required', 'uuid', 'distinct'],
            'mutations.*.entity' => ['required', 'string', 'max:64'],
            'mutations.*.entity_id' => ['required', 'uuid'],
            'mutations.*.op' => ['required', Rule::in(Mutation::OPS)],
            'mutations.*.payload' => ['present', 'array'],
            'mutations.*.occurred_at' => ['nullable', 'date'],
        ]);

        $device = $this->activeDevice($data['device_id']);
        $userId = $request->user()->id;

        $mutations = array_map(fn (array $m) => new Mutation(
            mutationId: $m['mutation_id'],
            entity: $m['entity'],
            entityId: $m['entity_id'],
            op: $m['op'],
            payload: $m['payload'],
            occurredAt: isset($m['occurred_at']) ? CarbonImmutable::parse($m['occurred_at']) : null,
            deviceId: $device->id,
            userId: $userId,
        ), $data['mutations']);

        $results = $this->sync->push($mutations);
        $device->forceFill(['last_seen_at' => now()])->save();

        return ApiResponse::ok([
            'results' => $results,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/sync/pull?device_id=&cursor=&limit= — cambios de la comunidad posteriores al cursor.
     */
    public function pull(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'uuid'],
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_BATCH],
        ]);

        $device = $this->activeDevice($data['device_id']);
        $device->forceFill(['last_seen_at' => now()])->save();

        // Cuota del mes y multas vencidas, para que el equipo las reciba en esta misma descarga.
        if (($community = $request->user()->community) !== null) {
            $this->cobroAutomatico->runIfDue($community);
        }

        return ApiResponse::ok([
            ...$this->sync->pull((int) ($data['cursor'] ?? 0), (int) ($data['limit'] ?? self::MAX_BATCH), $request->user()),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function activeDevice(string $id): Device
    {
        // El filtro de comunidad hace que un equipo ajeno no se encuentre.
        $device = Device::find($id);
        if ($device === null && $this->context->runAsSystem(fn () => Device::whereKey($id)->exists())) {
            throw new ApiException('device_other_community', 'Este equipo pertenece a otra comunidad.', 403);
        }
        if ($device === null) {
            throw new ApiException('device_not_registered', 'Este equipo aún no está registrado. Inicia sesión con internet una vez para registrarlo.', 409);
        }
        if (! $device->is_active) {
            throw new ApiException('device_inactive', 'El equipo fue desactivado por el administrador.', 403);
        }

        return $device;
    }
}
