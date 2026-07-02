<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AnyJwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $scope = $payload->get('scope');

            if ($scope === 'customer') {
                $customerId = (int) $payload->get('sub');
                $customer = Customer::find($customerId);
                if (!$customer || $customer->status !== 'active') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized customer.',
                        'code' => 401,
                    ], 401);
                }
                $request->attributes->set('auth_user', $customer);
            } elseif ($scope === 'employee') {
                $employeeId = (int) $payload->get('sub');
                $employee = Employee::find($employeeId);
                if (!$employee || !$employee->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized employee.',
                        'code' => 401,
                    ], 401);
                }
                $request->attributes->set('auth_user', $employee);
            } else {
                $userId = (int) $payload->get('sub');
                $user = User::find($userId);
                if (!$user || !$user->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized user.',
                        'code' => 401,
                    ], 401);
                }
                $request->attributes->set('auth_user', $user);
            }
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token missing or invalid.',
                'code' => 401,
            ], 401);
        }

        return $next($request);
    }
}
