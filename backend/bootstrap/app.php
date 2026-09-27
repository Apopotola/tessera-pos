<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Cookie-based Sanctum auth for the Next.js SPA (same hostname in local dev).
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $envelope = static fn (string $message, int $status, array|string $errors = []) => response()->json([
            'success' => false,
            'message' => $message,
            'statusCode' => $status,
            'errors' => $errors,
        ], $status);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // Render every API exception in the shared ApiResponse envelope.
        $exceptions->render(function (ValidationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope('The given data was invalid.', 422, $e->errors());
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope('Unauthenticated.', 401);
            }
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                // Keep a specific reason ("Someone else must approve…"); replace only the framework defaults.
                $message = $e->getMessage();
                $generic = $message === '' || in_array($message, ['This action is unauthorized.', 'Forbidden'], true);

                return $envelope($generic ? 'You are not allowed to perform this action.' : $message, 403);
            }
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope('Resource not found.', 404);
            }
        });
        // A record id that is not a number (e.g. /suppliers/abc) cannot exist: 404, not a database error.
        $exceptions->render(function (QueryException $e, Request $request) use ($envelope) {
            if ($request->is('api/*') && ($e->errorInfo[0] ?? null) === '22P02') {
                return $envelope('Resource not found.', 404);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($envelope) {
            if ($request->is('api/*')) {
                return $envelope($e->getMessage() ?: 'Request failed.', $e->getStatusCode());
            }
        });
    })->create();
