<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_registration_requires_email_verification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rental Customer',
            'email' => 'customer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'device_name' => 'ios-app',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonPath('requires_vendor_approval', false)
            ->assertJsonPath('user.email', 'customer@example.com')
            ->assertJsonPath('user.role', 'customer')
            ->assertJsonMissingPath('access_token');

        $this->assertDatabaseHas('users', [
            'email' => 'customer@example.com',
            'role' => 'customer',
            'status' => 'pending',
        ]);

        $user = User::query()->where('email', 'customer@example.com')->sole();

        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_verifying_email_activates_customer(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => 'pending',
        ]);

        $response = $this->getJson($this->verificationUrl($user));

        $response
            ->assertOk()
            ->assertJsonPath('requires_vendor_approval', false)
            ->assertJsonPath('user.status', 'active');

        $user->refresh();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('active', $user->status);
    }

    public function test_verifying_email_does_not_activate_vendor(): void
    {
        $vendor = User::factory()->vendor()->unverified()->create([
            'status' => 'pending',
        ]);

        $this->getJson($this->verificationUrl($vendor))
            ->assertOk()
            ->assertJsonPath('requires_vendor_approval', true)
            ->assertJsonPath('user.status', 'pending');

        $vendor->refresh();

        $this->assertTrue($vendor->hasVerifiedEmail());
        $this->assertSame('pending', $vendor->status);
    }

    public function test_replaying_verification_link_does_not_reactivate_pending_customer(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => 'pending',
        ]);
        $verificationUrl = $this->verificationUrl($user);

        $this->getJson($verificationUrl)->assertOk();

        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->patchJson("/api/v1/users/{$user->id}", [
                'status' => 'pending',
            ])
            ->assertOk();

        $this->getJson($verificationUrl)
            ->assertOk()
            ->assertJsonPath('user.status', 'pending');

        $this->assertSame('pending', $user->refresh()->status);
    }

    public function test_verification_notification_can_be_requested_again_without_account_enumeration(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'status' => 'pending',
        ]);

        $knownAccountResponse = $this->postJson('/api/v1/auth/email/verification-notification', [
            'email' => $user->email,
        ]);
        $unknownAccountResponse = $this->postJson('/api/v1/auth/email/verification-notification', [
            'email' => 'missing@example.com',
        ]);

        $knownAccountResponse->assertOk();
        $unknownAccountResponse->assertOk();

        $this->assertSame($knownAccountResponse->json(), $unknownAccountResponse->json());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verification_link_must_have_a_valid_signature(): void
    {
        $user = User::factory()->unverified()->create([
            'status' => 'pending',
        ]);

        $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->email))
            ->assertForbidden();

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_unverified_user_cannot_login(): void
    {
        $user = User::factory()->unverified()->create([
            'password' => 'password',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verified_vendor_can_onboard_but_requires_admin_approval(): void
    {
        $vendor = User::factory()->vendor()->create([
            'password' => 'password',
            'status' => 'pending',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $vendor->email,
            'password' => 'password',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('requires_vendor_approval', true);

        $onboardingToken = $loginResponse->json('access_token');
        $this->assertSame(['vendor:onboarding'], $vendor->tokens()->sole()->abilities);

        $profileResponse = $this
            ->withToken($onboardingToken)
            ->postJson('/api/v1/vendors', [
                'user_id' => $vendor->id,
                'business_name' => 'Verified Tools',
                'company_code' => '123456789',
            ]);

        $profileResponse
            ->assertCreated()
            ->assertJsonPath('verification_status', 'pending');

        $this
            ->withToken($onboardingToken)
            ->getJson('/api/v1/bookings')
            ->assertForbidden();

        $profile = VendorProfile::query()->sole();
        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('admin-client')->plainTextToken;

        app('auth')->forgetGuards();

        $this
            ->withToken($adminToken)
            ->patchJson("/api/v1/vendors/{$profile->id}", [
                'verification_status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('verification_status', 'approved');

        $this->assertSame('active', $vendor->refresh()->status);
        $this->assertSame(0, $vendor->tokens()->count());

        $approvedLoginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $vendor->email,
            'password' => 'password',
        ]);

        $approvedLoginResponse
            ->assertOk()
            ->assertJsonPath('requires_vendor_approval', false);

        $this->assertSame(['*'], $vendor->tokens()->sole()->abilities);
    }

    public function test_pending_vendor_can_logout_onboarding_token(): void
    {
        $vendor = User::factory()->vendor()->create([
            'status' => 'pending',
        ]);
        $token = $vendor->createToken('onboarding', ['vendor:onboarding'])->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_cannot_approve_vendor_with_unverified_email(): void
    {
        $vendor = User::factory()->vendor()->unverified()->create([
            'status' => 'pending',
        ]);
        $profile = VendorProfile::factory()->create([
            'user_id' => $vendor->id,
            'verification_status' => 'pending',
        ]);
        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->patchJson("/api/v1/vendors/{$profile->id}", [
                'verification_status' => 'approved',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_status');

        $this->assertSame('pending', $profile->refresh()->verification_status);
        $this->assertSame('pending', $vendor->refresh()->status);
    }

    public function test_admin_cannot_approve_profile_for_a_new_unverified_owner(): void
    {
        $currentVendor = User::factory()->vendor()->create();
        $newVendor = User::factory()->vendor()->unverified()->create([
            'status' => 'pending',
        ]);
        $profile = VendorProfile::factory()->create([
            'user_id' => $currentVendor->id,
            'verification_status' => 'pending',
        ]);
        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->patchJson("/api/v1/vendors/{$profile->id}", [
                'user_id' => $newVendor->id,
                'verification_status' => 'approved',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_status');

        $profile->refresh();

        $this->assertSame($currentVendor->id, $profile->user_id);
        $this->assertSame('pending', $profile->verification_status);
        $this->assertSame('pending', $newVendor->refresh()->status);
    }

    public function test_reassigning_and_approving_profile_updates_the_new_owner(): void
    {
        $currentVendor = User::factory()->vendor()->create();
        $newVendor = User::factory()->vendor()->create([
            'status' => 'pending',
        ]);
        $profile = VendorProfile::factory()->create([
            'user_id' => $currentVendor->id,
            'verification_status' => 'pending',
        ]);
        $currentVendor->createToken('current-vendor');
        $newVendor->createToken('new-vendor', ['vendor:onboarding']);
        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->patchJson("/api/v1/vendors/{$profile->id}", [
                'user_id' => $newVendor->id,
                'verification_status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('user_id', $newVendor->id)
            ->assertJsonPath('verification_status', 'approved');

        $this->assertSame('pending', $currentVendor->refresh()->status);
        $this->assertSame(0, $currentVendor->tokens()->count());
        $this->assertSame('active', $newVendor->refresh()->status);
        $this->assertSame(0, $newVendor->tokens()->count());
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_rejecting_vendor_deactivates_active_tools(): void
    {
        $vendor = User::factory()->vendor()->create();
        $profile = VendorProfile::factory()->create([
            'user_id' => $vendor->id,
            'verification_status' => 'approved',
        ]);
        $activeTool = Tool::factory()->create([
            'vendor_id' => $profile->id,
            'status' => 'active',
        ]);
        $inactiveTool = Tool::factory()->create([
            'vendor_id' => $profile->id,
            'status' => 'inactive',
        ]);
        $vendor->createToken('vendor-client');
        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->patchJson("/api/v1/vendors/{$profile->id}", [
                'verification_status' => 'rejected',
            ])
            ->assertOk()
            ->assertJsonPath('verification_status', 'rejected');

        $this->assertSame('blocked', $vendor->refresh()->status);
        $this->assertSame(0, $vendor->tokens()->count());
        $this->assertSame('inactive', $activeTool->refresh()->status);
        $this->assertSame('inactive', $inactiveTool->refresh()->status);
    }

    public function test_vendor_identity_update_requires_reapproval(): void
    {
        $vendor = User::factory()->vendor()->create();
        $profile = VendorProfile::factory()->create([
            'user_id' => $vendor->id,
            'verification_status' => 'approved',
        ]);
        $tool = Tool::factory()->create([
            'vendor_id' => $profile->id,
            'status' => 'active',
        ]);
        $token = $vendor->createToken('vendor-client')->plainTextToken;

        $this
            ->withToken($token)
            ->patchJson("/api/v1/vendors/{$profile->id}", ['business_name' => 'Changed Legal Identity'])
            ->assertOk()
            ->assertJsonPath('business_name', 'Changed Legal Identity')
            ->assertJsonPath('verification_status', 'pending');

        $this->assertSame('pending', $vendor->refresh()->status);
        $this->assertSame(0, $vendor->tokens()->count());
        $this->assertSame('inactive', $tool->refresh()->status);
    }

    public function test_admin_cannot_bypass_vendor_profile_approval(): void
    {
        $vendor = User::factory()->vendor()->create([
            'status' => 'pending',
        ]);
        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->patchJson("/api/v1/users/{$vendor->id}", [
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('pending', $vendor->refresh()->status);
    }

    public function test_admin_cannot_change_an_active_customer_to_a_vendor_without_approval(): void
    {
        $customer = User::factory()->customer()->create();
        $customer->createToken('customer-client');
        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->patchJson("/api/v1/users/{$customer->id}", [
                'role' => 'vendor',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $customer->refresh();

        $this->assertSame('customer', $customer->role);
        $this->assertSame('active', $customer->status);
        $this->assertSame(1, $customer->tokens()->count());
    }

    public function test_admin_cannot_create_an_active_vendor_without_approval(): void
    {
        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->postJson('/api/v1/users', [
                'name' => 'Unapproved Vendor',
                'email' => 'unapproved-vendor@example.com',
                'password' => 'password',
                'role' => 'vendor',
                'status' => 'active',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseMissing('users', [
            'email' => 'unapproved-vendor@example.com',
        ]);
    }

    public function test_admin_created_user_receives_email_verification_notification(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->postJson('/api/v1/users', [
                'name' => 'Invited Customer',
                'email' => 'invited-customer@example.com',
                'password' => 'password',
                'role' => 'customer',
            ])
            ->assertCreated()
            ->assertJsonPath('email', 'invited-customer@example.com');

        $user = User::query()->where('email', 'invited-customer@example.com')->sole();

        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertSame('pending', $user->status);
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_deleting_vendor_profile_returns_owner_to_onboarding(): void
    {
        $vendor = User::factory()->vendor()->create();
        $profile = VendorProfile::factory()->create([
            'user_id' => $vendor->id,
            'verification_status' => 'approved',
        ]);
        $token = $vendor->createToken('vendor-client')->plainTextToken;

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/vendors/{$profile->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($profile);
        $this->assertSame('pending', $vendor->refresh()->status);
        $this->assertSame(0, $vendor->tokens()->count());
    }

    public function test_vendor_can_restart_onboarding_after_deleting_profile(): void
    {
        $vendor = User::factory()->vendor()->create();
        $profile = VendorProfile::factory()->create([
            'user_id' => $vendor->id,
            'verification_status' => 'approved',
        ]);

        $this
            ->withToken($vendor->createToken('vendor-client')->plainTextToken)
            ->deleteJson("/api/v1/vendors/{$profile->id}")
            ->assertNoContent();

        $response = $this
            ->withToken($vendor->createToken('onboarding-client')->plainTextToken)
            ->postJson('/api/v1/vendors', [
                'user_id' => $vendor->id,
                'business_name' => 'Restarted Rentals',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('id', $profile->id)
            ->assertJsonPath('business_name', 'Restarted Rentals')
            ->assertJsonPath('verification_status', 'pending');

        $this->assertNotSoftDeleted($profile->refresh());
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

    public function test_active_user_can_get_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('test-client')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('status', 'active');
    }

    public function test_pending_vendor_can_get_authenticated_user(): void
    {
        $vendor = User::factory()->vendor()->create([
            'status' => 'pending',
        ]);

        $this
            ->withToken($vendor->createToken('onboarding-client', ['vendor:onboarding'])->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $vendor->id)
            ->assertJsonPath('role', 'vendor')
            ->assertJsonPath('status', 'pending');
    }

    public function test_pending_customer_cannot_get_authenticated_user(): void
    {
        $customer = User::factory()->customer()->create([
            'status' => 'pending',
        ]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }

    public function test_blocked_user_cannot_get_authenticated_user(): void
    {
        $user = User::factory()->blocked()->create();

        $this
            ->withToken($user->createToken('test-client')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }

    public function test_unverified_user_cannot_get_authenticated_user(): void
    {
        $user = User::factory()->unverified()->create();

        $this
            ->withToken($user->createToken('test-client')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }

    public function test_guest_cannot_get_authenticated_user(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
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

    public function test_customer_can_update_their_own_profile(): void
    {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('test-client')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->patchJson("/api/v1/users/{$user->id}", [
                'name' => 'Updated Customer',
                'phone' => '+37060000000',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('name', 'Updated Customer')
            ->assertJsonPath('phone', '+37060000000');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Customer',
            'phone' => '+37060000000',
        ]);
    }

    public function test_customer_cannot_update_another_users_profile(): void
    {
        $customer = User::factory()->customer()->create();
        $otherUser = User::factory()->create([
            'name' => 'Original Name',
        ]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/users/{$otherUser->id}", [
                'name' => 'Unauthorized Update',
            ])
            ->assertForbidden();

        $this->assertSame('Original Name', $otherUser->refresh()->name);
    }

    public function test_customer_cannot_update_their_own_role_or_status(): void
    {
        $customer = User::factory()->customer()->create();

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/users/{$customer->id}", [
                'role' => 'admin',
                'status' => 'blocked',
            ])
            ->assertForbidden();

        $customer->refresh();

        $this->assertSame('customer', $customer->role);
        $this->assertSame('active', $customer->status);
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

    public function test_blocking_vendor_deactivates_active_tools(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = User::factory()->vendor()->create();
        $vendorProfile = VendorProfile::factory()->create([
            'user_id' => $vendor->id,
            'verification_status' => 'approved',
        ]);
        $tool = Tool::factory()->create([
            'vendor_id' => $vendorProfile->id,
            'status' => 'active',
        ]);

        $this
            ->withToken($admin->createToken('admin-client')->plainTextToken)
            ->patchJson("/api/v1/users/{$vendor->id}", [
                'status' => 'blocked',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'blocked');

        $this->assertSame('inactive', $tool->refresh()->status);
    }

    public function test_updating_email_requires_verification_and_revokes_existing_tokens(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $adminToken = $admin->createToken('admin-client')->plainTextToken;
        $user->createToken('test-client');

        $this
            ->withToken($adminToken)
            ->patchJson("/api/v1/users/{$user->id}", [
                'email' => 'new-email@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('email', 'new-email@example.com')
            ->assertJsonPath('email_verified_at', null);

        $user->refresh();

        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $admin->tokens()->count());
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_updating_password_revokes_existing_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $adminToken = $admin->createToken('admin-client')->plainTextToken;
        $userToken = $user->createToken('test-client')->plainTextToken;

        $this
            ->withToken($adminToken)
            ->patchJson("/api/v1/users/{$user->id}", [
                'password' => 'new-password',
            ])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $admin->tokens()->count());

        app('auth')->forgetGuards();

        $this->withToken($userToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_updating_role_revokes_existing_tokens(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'role' => 'customer',
        ]);
        $adminToken = $admin->createToken('admin-client')->plainTextToken;
        $userToken = $user->createToken('test-client')->plainTextToken;

        $this
            ->withToken($adminToken)
            ->patchJson("/api/v1/users/{$user->id}", [
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('role', 'admin');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $admin->tokens()->count());

        app('auth')->forgetGuards();

        $this->withToken($userToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
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

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'api.verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );
    }
}
