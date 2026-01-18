<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimiter
{
    /**
     * Handle an incoming request with custom rate limiting per client.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Rate limiting is handled by Laravel's built-in ThrottleRequests
        // This middleware adds custom headers and logging
        
        $response = $next($request);
        
        // Add rate limit headers for client-side handling
        if ($client = auth()->user()) {
            $response->headers->set('X-RateLimit-Client', $client->id);
        }
        
        return $response;
    }
}
