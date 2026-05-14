<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * Register a new user (Patient)
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role' => 'patient',
            ]);

            // Create patient profile
            Patient::create([
                'user_id' => $user->id,
            ]);

            $token = $user->createToken('auth-token')->plainTextToken;

            Log::info('New patient registered', ['user_id' => $user->id, 'email' => $user->email]);

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'user' => new UserResource($user),
                    'token' => $token,
                ],
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Login user
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                Log::warning('Login failed - invalid credentials', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid email or password',
                ], Response::HTTP_UNAUTHORIZED);
            }

            if (!$user->is_active) {
                Log::warning('Login failed - account inactive', ['user_id' => $user->id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been deactivated. Please contact support.',
                ], Response::HTTP_FORBIDDEN);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            Log::info('User logged in', ['user_id' => $user->id, 'role' => $user->role]);

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => new UserResource($user),
                    'token' => $token,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Login failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Login failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            Log::info('User logged out', ['user_id' => $request->user()->id]);

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Logout failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Logout failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get current user
     */
    public function me(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => new UserResource($request->user()),
            ]);
        } catch (\Exception $e) {
            Log::error('Get current user failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get user data.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}