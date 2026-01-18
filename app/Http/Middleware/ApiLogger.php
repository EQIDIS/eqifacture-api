<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log all API requests (without sensitive data)
 */
class ApiLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        // Process request
        $response = $next($request);
        
        // Calculate duration
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        // Log request (without sensitive data)
        Log::channel('api')->info('API Request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            // Never log: certificate, private_key, passphrase
        ]);
        
        // Add timing header
        $response->headers->set('X-Response-Time', $duration . 'ms');
        
        return $response;
    }
}
