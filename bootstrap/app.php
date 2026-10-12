<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetCommunityContext;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // La API no tiene pantalla de login: sin token responde 401 en JSON, nunca redirige.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        $middleware->alias([
            'community' => SetCommunityContext::class,
            'superadmin' => EnsureSuperAdmin::class,
            'active' => EnsureUserIsActive::class,
            'perm' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toda respuesta de error bajo /api tiene la forma { "error": { code, message, details? } }.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ApiException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->details),
                $e instanceof ValidationException => ApiResponse::error('validation_error', 'Revisa los datos: hay campos con errores.', 422, $e->errors()),
                $e instanceof AuthenticationException => ApiResponse::error('unauthenticated', 'Se requiere iniciar sesión.', 401),
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => ApiResponse::error('forbidden', 'No tiene permiso para esta acción.', 403),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error('not_found', 'Recurso no encontrado.', 404),
                $e instanceof MethodNotAllowedHttpException => ApiResponse::error('method_not_allowed', 'Método no permitido.', 405),
                $e instanceof ThrottleRequestsException => ApiResponse::error('too_many_requests', 'Demasiadas solicitudes; intente más tarde.', 429),
                $e instanceof HttpExceptionInterface => ApiResponse::error('http_error', $e->getMessage() ?: 'Error en la solicitud.', $e->getStatusCode()),
                default => ApiResponse::error(
                    'server_error',
                    config('app.debug') ? $e->getMessage() : 'Ocurrió un problema en el servidor. Intenta de nuevo en unos minutos.',
                    500,
                ),
            };
        });
    })->create();
