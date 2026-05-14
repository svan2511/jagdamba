<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (!$request->user()) {
            Log::warning('Unauthorized access attempt', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please login first.',
            ], 401);
        }

        if ($request->user()->role !== $role && !$request->user()->isAdmin()) {
            Log::warning('Access denied - insufficient permissions', [
                'user_id' => $request->user()->id,
                'required_role' => $role,
                'user_role' => $request->user()->role,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Access denied. You do not have permission to access this resource.',
            ], 403);
        }

        return $next($request);
    }
}