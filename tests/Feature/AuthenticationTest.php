<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rental Customer',
            'email' => 'customer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'device_name' => 'ios-app',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'customer@example.com')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonStructure([
                'access_token',
                'expires_at',
                'token_type',
                'user' => ['id', 'name', 'email', 'role', 'status'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'customer@example.com',
            'role' => 'customer',
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertAccessTokenExpiresAt($response->json('expires_at'));
    }

    public function test_user_can_login_and_receive_token(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'password',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'ios-app',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'customer@example.com')
            ->assertJsonStructure(['access_token', 'expires_at', 'token_type', 'user']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertAccessTokenExpiresAt($response->json('expires_at'));
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_cannot_login_when_account_is_not_active(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
            'status' => 'blocked',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_route_is_throttled(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_customer_token_cannot_manage_users(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
        ]);
        $token = $user->createToken('test-client')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/users');

        $response->assertForbidden();
    }

    public function test_admin_token_can_manage_users(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);
        $token = $admin->createToken('test-client')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/users');

        $response->assertOk();
    }

    public function test_blocked_user_cannot_access_protected_api_with_existing_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;

        $user->update(['status' => 'blocked']);

        $response = $this
            ->withToken($token)
            ->getJson('/api/user');

        $response->assertForbidden();
    }

    public function test_updating_user_to_non_active_revokes_existing_tokens(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);
        $user = User::factory()->create();
        $adminToken = $admin->createToken('admin-client')->plainTextToken;
        $user->createToken('test-client');

        $response = $this
            ->withToken($adminToken)
            ->patchJson("/api/v1/users/{$user->id}", [
                'status' => 'blocked',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'blocked');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_user_can_access_protected_api_with_bearer_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/user');

        $response
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_expired_bearer_token_cannot_access_protected_api(): void
    {
        config(['sanctum.expiration' => 1]);
        app('auth')->forgetGuards();

        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;

        $user->tokens()->first()->forceFill([
            'created_at' => now()->subMinutes(2),
        ])->save();

        $response = $this
            ->withToken($token)
            ->getJson('/api/user');

        $response->assertUnauthorized();
    }

    public function test_user_can_logout_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-client')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->postJson('/api/v1/auth/logout');

        $response->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function assertAccessTokenExpiresAt(string $expiresAt): void
    {
        $token = PersonalAccessToken::query()->sole();

        $this->assertNotNull($token->expires_at);
        $this->assertSame($token->expires_at->toISOString(), $expiresAt);
        $this->assertTrue($token->expires_at->isAfter(now()->addMinutes(config('sanctum.expiration'))->subMinute()));
        $this->assertTrue($token->expires_at->isBefore(now()->addMinutes(config('sanctum.expiration'))->addMinute()));
    }
}
