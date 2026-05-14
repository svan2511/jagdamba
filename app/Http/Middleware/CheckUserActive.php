<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        // Check if user account is active
        if (!$user->is_active) {
            // Log the user out - delete all their tokens
            $user->tokens()->delete();

            Log::warning('Inactive user attempted access', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Please contact administrator.',
                'code' => 'ACCOUNT_DEACTIVATED',
            ], 403);
        }

        return $next($request);
    }
}