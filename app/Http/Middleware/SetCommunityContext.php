<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Tenancy\CurrentCommunity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la comunidad de la petición a partir del usuario autenticado.
 * Va después de auth:sanctum en toda ruta que toque datos de una comunidad.
 */
class SetCommunityContext
{
    public function __construct(private readonly CurrentCommunity $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->community_id === null) {
            throw new ApiException('community_required', 'Esta acción es solo para usuarios de una comunidad.', 403);
        }
        if (! $user->community?->is_active) {
            throw new ApiException('community_inactive', 'La comunidad está desactivada.', 403);
        }

        $previous = $this->context->id();
        $this->context->set($user->community_id);

        try {
            return $next($request);
        } finally {
            $this->context->set($previous);
        }
    }
}
