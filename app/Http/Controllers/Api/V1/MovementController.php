<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Movements\MovementRegistry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CDU23: anular un movimiento con motivo. El movimiento queda anulado, no se borra.
 */
class MovementController extends Controller
{
    public function __construct(private readonly MovementRegistry $registry) {}

    public function void(Request $request, string $entity, string $id): JsonResponse
    {
        $model = $this->registry->modelFor($entity)
            ?? throw new ApiException('not_found', 'Tipo de movimiento no reconocido.', 404);

        $movement = $model::find($id) ?? throw new ApiException('not_found', 'Movimiento no encontrado.', 404);
        $movement->voidWith($request->user(), $request->input('reason'));

        return ApiResponse::ok($movement->fresh()->toArray());
    }
}
