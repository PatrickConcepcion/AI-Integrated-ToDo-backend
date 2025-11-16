<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
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

        try {
            $result = DB::transaction(function () use ($validated) {
                // Create user
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                ]);

                // Assign role
                $user->assignRole('user');

                // Create conversation
                Conversation::create(['user_id' => $user->id]);

                return $user;
            });

            // Login the user and generate token
            /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
            $guard = Auth::guard('api');
            $token = $guard->login($result);

            // Log token issuance details for verification
            $ttlMinutes = $guard->factory()->getTTL();
            Log::info('Token issued on register', [
                'time' => now()->toDateTimeString(),
                'user_id' => $result->id,
                'ttl_minutes' => $ttlMinutes,
                'ttl_seconds' => $ttlMinutes * 60,
                'refresh_ttl_minutes' => config('jwt.refresh_ttl'),
                'refresh_iat' => config('jwt.refresh_iat'),
            ]);

            return $this->respondWithToken($token);

        } catch (RoleDoesNotExist $e) {
            Log::error('Role assignment failed during registration', [
                'error' => $e->getMessage(),
                'email' => $validated['email'],
            ]);

            return response()->json([
                'message' => 'Registration failed. Please contact support.'
            ], 500);

        } catch (\Exception $e) {
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'email' => $validated['email'],
            ]);

            return response()->json([
                'message' => 'Registration failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Authenticate user and return a JWT.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = Auth::guard('api');
        if (! $token = $guard->attempt($request->validated())) {
            // Log failed login attempt
            Log::warning('Failed login attempt', [
                'email' => $request->validated()['email'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'The provided credentials are incorrect.'
            ], 401);
        }

        // Check if user is banned
        $user = $guard->user();
        if ($user && $user->is_banned) {
            // Log banned user login attempt
            Log::warning('Banned user login attempt', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Invalidate the token we just created
            $guard->logout();

            return response()->json([
                'message' => 'Your account has been banned. Please contact support.'
            ], 403);
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

        // Ensure user has a conversation
        Conversation::firstOrCreate(['user_id' => $user->id]);

        return $this->respondWithToken($token);
    }

    /**
     * Return the authenticated user's profile.
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('roles');

        return (new UserResource($user))
            ->additional(['message' => 'User profile retrieved successfully.']);
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

            // Check if the authenticated user is banned
            $user = $guard->user();
            if ($user && $user->is_banned) {
                // Log banned user refresh attempt
                Log::warning('Banned user token refresh attempt', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'ip' => $request->ip(),
                ]);

                // Invalidate the token
                $guard->logout();

                return response()->json([
                    'message' => 'Your account has been banned. Please contact support.'
                ], 403);
            }

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
     * Handle a password reset link request for a guest user.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->input('email');

        $status = Password::sendResetLink(['email' => $email]);

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'If an account exists for that email, a password reset link has been sent.',
            ]);
        }

        Log::warning('Password reset link sending returned non-success status', [
            'email_hash' => hash('sha256', $email),
            'status' => $status,
        ]);

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    /**
     * Handle an incoming password reset request.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password reset successfully.',
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 422);
    }

    /**
     * Change password for an authenticated user.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Not authenticated.',
            ], 401);
        }

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
            ], 422);
        }

        $user->password = Hash::make($request->input('password'));
        $user->setRememberToken(Str::random(60));
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully!',
        ]);
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

    /**
     * Get all users (admin only).
     */
    public function index(): JsonResponse
    {
        $users = User::with('roles')->get();

        return response()->json([
            'data' => UserResource::collection($users),
            'message' => 'Users retrieved successfully.',
        ]);
    }

    /**
     * Ban a user (admin only).
     */
    public function banUser(User $user): JsonResponse
    {
        $user->update(['is_banned' => true]);

        return response()->json([
            'message' => 'User banned successfully.',
        ]);
    }

    /**
     * Unban a user (admin only).
     */
    public function unbanUser(User $user): JsonResponse
    {
        $user->update(['is_banned' => false]);

        return response()->json([
            'message' => 'User unbanned successfully.',
        ]);
    }
}
