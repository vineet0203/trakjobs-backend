<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class CustomerJwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();

            if (($payload->get('scope') ?? null) !== 'customer') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid customer token scope.',
                    'code' => 401,
                ], 401);
            }

            $customerId = (int) $payload->get('sub');
            $customer = Customer::find($customerId);

            if (!$customer ||  !in_array($customer->role, ["customer", "client"])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found.',
                    'code' => 401,
                ], 401);
            }

            if ($customer->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer account is inactive.',
                    'code' => 403,
                ], 403);
            }

            $request->attributes->set('customer', [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'role' => $customer->role,
                'status' => $customer->status,
                'verification_status' => $customer->verification_status,
            ]);
        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer token expired.',
                'code' => 401,
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer token invalid.',
                'code' => 401,
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer token missing.',
                'code' => 401,
            ], 401);
        }

        return $next($request);
    }
}