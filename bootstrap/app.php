<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Rate limiting: 60 requests per minute by default
        $middleware->throttleApi('60,1');
        
        // CSRF exempt for API routes
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
        
        // Append custom middleware to API routes
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\Cors::class,
            \App\Http\Middleware\ApiLogger::class,
        ]);

        // Trust all proxies (standard for containerized deployments like Coolify/Dokploy)
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle rate limit exceeded
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rate limit exceeded. Please wait before making more requests.',
                    'retry_after' => $e->getHeaders()['Retry-After'] ?? 60,
                ], 429);
            }
        });
        
        // Handle generic exceptions for API
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && !config('app.debug')) {
                return response()->json([
                    'success' => false,
                    'message' => 'An error occurred. Please try again.',
                ], 500);
            }
        });
    })->create();
