<?php

namespace App\Http\Controllers\Api\V1;

use App\Comunidad\AjustesService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ajustes de cobro de la comunidad: los ve cualquier usuario de la comunidad; los cambia el administrador.
 */
class AjustesController extends Controller
{
    public function __construct(private readonly AjustesService $ajustes) {}

    public function show(): JsonResponse
    {
        return ApiResponse::ok(AjustesService::present($this->ajustes->current()));
    }

    public function update(Request $request): JsonResponse
    {
        return ApiResponse::ok(AjustesService::present($this->ajustes->update($request->user(), $request->all())));
    }
}
