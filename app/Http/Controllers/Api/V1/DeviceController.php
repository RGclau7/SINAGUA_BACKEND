<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use App\Support\ApiResponse;
use App\Tenancy\CurrentCommunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    public function __construct(private readonly CurrentCommunity $context) {}

    /**
     * Registra un equipo (idempotente por id) y le asigna su prefijo de folio.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $existing = Device::find($data['id']);
        if ($existing !== null) {
            return ApiResponse::ok($this->present($existing));
        }
        if ($this->context->runAsSystem(fn () => Device::whereKey($data['id'])->exists())) {
            throw new ApiException('device_other_community', 'Este equipo está registrado en otra comunidad.', 409);
        }

        $device = DB::transaction(function () use ($data, $request) {
            $device = Device::create([
                'id' => $data['id'],
                'name' => $data['name'],
                'receipt_prefix' => Device::nextReceiptPrefix(),
                'registered_by' => $request->user()->id,
                'is_active' => true,
                'last_seen_at' => now(),
            ]);

            AuditLog::record('device.registered', [
                'user_id' => $request->user()->id,
                'device_id' => $device->id,
                'entity' => 'devices',
                'entity_id' => $device->id,
                'data' => ['name' => $device->name, 'receipt_prefix' => $device->receipt_prefix],
                'created_at' => now(),
            ]);

            return $device;
        });

        return ApiResponse::ok($this->present($device), 201);
    }

    private function present(Device $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'receipt_prefix' => $device->receipt_prefix,
            'is_active' => $device->is_active,
            'community_id' => $device->community_id,
        ];
    }
}
