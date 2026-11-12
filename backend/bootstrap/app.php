<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\SetLocale;
use App\Modules\Imports\Support\ImportFileException;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->api(append: [
            SetLocale::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'idempotent' => Idempotent::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (DomainException $exception, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'domain_error',
                ], 422);
            }
        });

        $exceptions->render(function (ImportFileException $exception, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error($exception->getMessage(), $exception->errorCode, $exception->status, ['params' => (object) $exception->params]);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage() ?: 'Forbidden.',
                    'code' => 'forbidden',
                ], 403);
            }
        });
    })->create();
