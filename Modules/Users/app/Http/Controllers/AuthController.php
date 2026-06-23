<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use DateTimeInterface;
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
        $deviceName = $validated['device_name'] ?? $request->userAgent() ?? 'api-token';

        unset($validated['device_name']);

        $user = User::create([
            ...$validated,
            'role' => $validated['role'] ?? 'customer',
            'status' => 'active',
        ]);

        $accessToken = $this->createAccessToken($user, $deviceName);

        return response()->json([
            'access_token' => $accessToken->plainTextToken,
            'expires_at' => $accessToken->accessToken->expires_at->toISOString(),
            'token_type' => 'Bearer',
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

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $accessToken = $this->createAccessToken($user, $validated['device_name'] ?? $request->userAgent() ?? 'api-token');

        return response()->json([
            'access_token' => $accessToken->plainTextToken,
            'expires_at' => $accessToken->accessToken->expires_at->toISOString(),
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
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
        return $user->createToken($deviceName, ['*'], $this->tokenExpiresAt());
    }

    private function tokenExpiresAt(): DateTimeInterface
    {
        return now()->addMinutes(max((int) config('sanctum.expiration'), 1));
    }
}
