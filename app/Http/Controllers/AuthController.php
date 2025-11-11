<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        try {
            $user->assignRole('user');
        } catch (RoleDoesNotExist $e) {
            $user->delete();

            return response()->json([
                'message' => 'Registration failed. Please contact support.'
            ], 500);
        }

        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = Auth::guard('api');
        $token = $guard->login($user);

        // Log token issuance details for verification
        $ttlMinutes = $guard->factory()->getTTL();
        Log::info('Token issued on register', [
            'time' => now()->toDateTimeString(),
            'user_id' => $user->id,
            'ttl_minutes' => $ttlMinutes,
            'ttl_seconds' => $ttlMinutes * 60,
            'refresh_ttl_minutes' => config('jwt.refresh_ttl'),
            'refresh_iat' => config('jwt.refresh_iat'),
        ]);

        return $this->respondWithToken($token);
    }

    /**
     * Authenticate user and return a JWT.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = Auth::guard('api');
        if (! $token = $guard->attempt($credentials)) {
            // Log failed login attempt
            Log::warning('Failed login attempt', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'The provided credentials are incorrect.'
            ], 401);
        }

        // Log successful login
        $user = $guard->user();
        $ttlMinutes = $guard->factory()->getTTL();
        Log::info('Token issued on login', [
            'time' => now()->toDateTimeString(),
            'user_id' => $user?->id,
            'ttl_minutes' => $ttlMinutes,
            'ttl_seconds' => $ttlMinutes * 60,
            'refresh_ttl_minutes' => config('jwt.refresh_ttl'),
            'refresh_iat' => config('jwt.refresh_iat'),
        ]);

        return $this->respondWithToken($token);
    }

    /**
     * Return the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'User profile retrieved successfully.'
        ]);
    }

    /**
     * Invalidate the current token.
     */
    public function logout(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json([
                'message' => 'Not authenticated.'
            ], 401);
        }

        Auth::guard('api')->logout();

        return response()->json([
            'message' => 'Successfully logged out.'
        ]);
    }

    /**
     * Refresh the token and return a new one.
     */
    public function refresh(Request $request): JsonResponse
    {
        // Log when refresh is attempted
        Log::info('Token refresh attempt', [
            'time' => now()->toDateTimeString(),
            'ip' => $request->ip(),
        ]);

        try {
            /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
            $guard = Auth::guard('api');
            $token = $guard->refresh();

            // Log successful refresh with TTL info
            $ttlSeconds = $guard->factory()->getTTL() * 60;
            Log::info('Token refresh success', [
                'time' => now()->toDateTimeString(),
                'ip' => $request->ip(),
                'expires_in' => $ttlSeconds,
            ]);

            return $this->respondWithToken($token);

        } catch (TokenExpiredException $e) {
            Log::warning('Token refresh failed - expired', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => config('app.debug') ? $e->getTrace() : null,
            ], 401);

        } catch (TokenInvalidException $e) {
            Log::warning('Token refresh failed - invalid', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => config('app.debug') ? $e->getTrace() : null,
            ], 401);

        } catch (JWTException $e) {
            Log::warning('Token refresh failed - JWT error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'trace' => $e->getTraceAsString(),
            ]);

            // JWT errors during authentication should return 401
            // This includes token parsing errors, signature failures, etc.
            return response()->json([
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => config('app.debug') ? $e->getTrace() : null,
            ], 401);

        } catch (\Exception $e) {
            Log::error('Token refresh failed - general error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => config('app.debug') ? $e->getTrace() : null,
            ], 500);
        }
    }

    /**
     * Helper to format token response consistently.
     */
    protected function respondWithToken(string $token): JsonResponse
    {
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = Auth::guard('api');
        $ttlSeconds = $guard->factory()->getTTL() * 60;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttlSeconds,
        ]);
    }
}
