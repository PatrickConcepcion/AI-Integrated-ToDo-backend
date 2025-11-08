<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable CORS for API
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Register custom middleware aliases
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Custom exception reporting (logging) with user context
        $exceptions->report(function (\Throwable $e) {
            $context = [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ];

            // Add user context if available
            if (auth()->check()) {
                $context['user_id'] = auth()->id();
            }

            Log::error($e->getMessage(), $context);
        });

        // Custom JSON rendering for API requests
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->expectsJson()) {
                // Determine status code based on exception type
                $statusCode = 500; // default

                // Authentication-related exceptions should always return 401
                if ($e instanceof AuthenticationException ||
                    $e instanceof UnauthorizedHttpException ||
                    $e instanceof JWTException) {
                    $statusCode = 401;
                }
                // Otherwise, try to get status code from exception if available
                elseif (method_exists($e, 'getStatusCode')) {
                    $statusCode = $e->getStatusCode();
                }

                return response()->json([
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                    'trace' => config('app.debug')
                        ? collect($e->getTrace())->map(fn($t) => Arr::only($t, ['file', 'line', 'function', 'class']))->all()
                        : null,
                ], $statusCode);
            }
        });
    })->create();
