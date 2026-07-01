<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Modules\Users\Http\Requests\LoginRequest;
use Modules\Users\Http\Requests\RegisterRequest;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            ...$validated,
            'role' => $validated['role'] ?? 'customer',
            'status' => 'pending',
        ]);

        event(new Registered($user));

        return response()->json([
            'message' => __('Please verify your email address.'),
            'requires_email_verification' => true,
            'requires_vendor_approval' => $user->role === 'vendor',
            'user' => $user,
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => __('Please verify your email address before signing in.'),
            ]);
        }

        if ($user->status === 'pending' && $user->role !== 'vendor') {
            throw ValidationException::withMessages([
                'email' => __('Your account is awaiting approval.'),
            ]);
        }

        if (! in_array($user->status, ['active', 'pending'], true)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $accessToken = $this->createAccessToken($user, $validated['device_name'] ?? $request->userAgent() ?? 'api-token');

        return response()->json([
            'access_token' => $accessToken->plainTextToken,
            'expires_at' => $accessToken->accessToken->expires_at->toISOString(),
            'token_type' => 'Bearer',
            'requires_vendor_approval' => $user->status === 'pending',
            'user' => $user,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function logout(Request $request): Response
    {
        $accessToken = $request->user()->currentAccessToken();

        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        return response()->noContent();
    }

    private function createAccessToken(User $user, string $deviceName): NewAccessToken
    {
        $abilities = $user->status === 'active'
            ? ['*']
            : ['vendor:onboarding'];

        return $user->createToken($deviceName, $abilities, $this->tokenExpiresAt());
    }

    private function tokenExpiresAt(): DateTimeInterface
    {
        return now()->addMinutes(max((int) config('sanctum.expiration'), 1));
    }
}
