<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC por ruta: `->middleware('perm:usuarios.gestionar')`. Va después de `community`,
 * así el permiso siempre se ejerce dentro de la comunidad del usuario.
 */
class EnsurePermission
{
    /** Con varios permisos (`perm:a,b`) basta con tener uno. */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if ($user === null || ! collect($permissions)->contains(fn (string $p) => $user->can($p))) {
            throw new ApiException('forbidden', 'No tiene permiso para esta acción.', 403);
        }

        return $next($request);
    }
}
