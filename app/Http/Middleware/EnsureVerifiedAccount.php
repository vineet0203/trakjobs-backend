<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedAccount
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('auth_user') ?? auth()->user();

        // If authenticated and verification status is not verified, block request (excluding verification routes and auth info)
        // Skip verification for platform admins
        if ($user && isset($user->verification_status) && $user->verification_status !== 'verified' && !$user->isPlatformAdmin()) {
            if (!$request->is('api/v1/verification/*') && !$request->is('api/v1/auth/me') && !$request->is('api/v1/customer/me')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account must be verified to perform this action.',
                    'timestamp' => now()->toIso8601String(),
                    'code' => 403,
                    'error_code' => 'VERIFICATION_REQUIRED',
                ], 403);
            }
        }

        return $next($request);
    }
}
