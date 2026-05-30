<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        // Force JSON response for all API requests
        if ($request->is('api/*') || strpos($request->path(), 'api/') === 0) {
            $request->headers->set('Accept', 'application/json');
            
            // Ensure response is JSON
            $response = $next($request);
            
            if (!$response->headers->get('Content-Type')) {
                $response->header('Content-Type', 'application/json');
            }
            
            return $response;
        }

        return $next($request);
    }
}