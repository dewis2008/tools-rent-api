<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\Payments\Models\Payment;
use Modules\ToolImages\Models\ToolImage;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_create_categories(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/categories', [
                'name' => 'Drills',
                'slug' => 'drills',
            ]);

        $response->assertForbidden();
    }

    public function test_admin_can_create_categories(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/categories', [
                'name' => 'Drills',
                'slug' => 'drills',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('slug', 'drills');
    }

    public function test_vendor_can_create_tool_only_for_own_vendor_profile(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $otherVendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/tools', $this->toolPayload($vendorProfile, $category))
            ->assertCreated()
            ->assertJsonPath('vendor_id', $vendorProfile->id);

        $this
            ->withToken($token)
            ->postJson('/api/v1/tools', $this->toolPayload($otherVendorProfile, $category))
            ->assertForbidden();
    }

    public function test_vendor_cannot_create_tools_before_profile_approval(): void
    {
        $vendor = User::factory()->vendor()->create();
        $vendorProfile = VendorProfile::create([
            'user_id' => $vendor->id,
            'business_name' => 'Pending Rentals',
            'verification_status' => 'pending',
        ]);
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/tools', $this->toolPayload($vendorProfile, $category))
            ->assertForbidden();

        $this->assertDatabaseCount('tools', 0);
    }

    public function test_vendor_cannot_set_tool_status_on_create(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);

        $response = $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/tools', [
                ...$this->toolPayload($vendorProfile, $category),
                'status' => 'active',
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('tools', [
            'vendor_id' => $vendorProfile->id,
            'status' => 'active',
        ]);
    }

    public function test_vendor_cannot_set_privileged_vendor_profile_fields_on_create(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);

        $response = $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/vendors', [
                'user_id' => $vendor->id,
                'business_name' => 'Vendor Rentals',
                'verification_status' => 'approved',
                'rating' => 5,
            ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('vendor_profiles', [
            'user_id' => $vendor->id,
            'verification_status' => 'approved',
            'rating' => 5,
        ]);
    }

    public function test_admin_cannot_create_vendor_profile_for_non_vendor_account(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('admin-client')->plainTextToken;

        foreach (['customer', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this
                ->withToken($token)
                ->postJson('/api/v1/vendors', [
                    'user_id' => $user->id,
                    'business_name' => 'Invalid Vendor',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('user_id');
        }

        $this->assertDatabaseCount('vendor_profiles', 0);
    }

    public function test_customer_cannot_view_vendor_profiles(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $token = $customer->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/vendors')
            ->assertForbidden();

        $this
            ->withToken($token)
            ->getJson("/api/v1/vendors/{$vendorProfile->id}")
            ->assertForbidden();
    }

    public function test_vendor_profile_index_is_scoped_to_owned_profile_without_user_data(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);

        $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));

        $response = $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->getJson('/api/v1/vendors');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vendorProfile->id);

        $this->assertArrayNotHasKey('user', $response->json('data.0'));
    }

    public function test_vendor_can_view_own_vendor_profile_without_user_data(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);

        $response = $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/vendors/{$vendorProfile->id}");

        $response
            ->assertOk()
            ->assertJsonPath('id', $vendorProfile->id);

        $this->assertArrayNotHasKey('user', $response->json());
    }

    public function test_vendor_cannot_view_another_vendor_profile(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $otherVendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/vendors/{$otherVendorProfile->id}")
            ->assertForbidden();
    }

    public function test_vendor_cannot_update_another_vendors_tool(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $otherVendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $ownTool = $this->createTool($vendorProfile, $category);
        $otherTool = $this->createTool($otherVendorProfile, $category);
        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->patchJson("/api/v1/tools/{$ownTool->id}", ['title' => 'Updated drill'])
            ->assertOk()
            ->assertJsonPath('title', 'Updated drill');

        $this
            ->withToken($token)
            ->patchJson("/api/v1/tools/{$otherTool->id}", ['title' => 'Not yours'])
            ->assertForbidden();
    }

    public function test_customer_only_sees_active_tools(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $activeTool = $this->createTool($vendorProfile, $category);
        $activeTool->update(['status' => 'active']);

        foreach (['pending', 'inactive', 'rejected'] as $status) {
            $tool = $this->createTool($vendorProfile, $category);
            $tool->update(['status' => $status]);
        }

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->getJson('/api/v1/tools')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeTool->id);
    }

    public function test_customer_cannot_view_non_active_tool(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $tool = $this->createTool($vendorProfile, $category);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/tools/{$tool->id}")
            ->assertForbidden();
    }

    public function test_vendor_only_sees_own_unpublished_tools(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $otherVendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $ownPendingTool = $this->createTool($vendorProfile, $category);
        $ownPendingTool->update(['address' => 'Own private address']);
        $otherActiveTool = $this->createTool($otherVendorProfile, $category);
        $otherActiveTool->update([
            'address' => 'Published address',
            'status' => 'active',
        ]);
        $otherUnpublishedTools = collect(['pending', 'rejected', 'inactive'])
            ->map(function (string $status) use ($otherVendorProfile, $category): Tool {
                $tool = $this->createTool($otherVendorProfile, $category);
                $tool->update([
                    'address' => "Private {$status} address",
                    'status' => $status,
                ]);

                return $tool;
            });
        $token = $vendor->createToken('test-client')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/tools')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$ownPendingTool->id, $otherActiveTool->id],
            collect($response->json('data'))->pluck('id')->all(),
        );

        foreach ($otherUnpublishedTools as $tool) {
            $response->assertJsonMissing(['address' => $tool->address]);

            $this
                ->withToken($token)
                ->getJson("/api/v1/tools/{$tool->id}")
                ->assertForbidden();
        }

        $this
            ->withToken($token)
            ->getJson("/api/v1/tools/{$ownPendingTool->id}")
            ->assertOk()
            ->assertJsonPath('address', 'Own private address');
    }

    public function test_customer_only_sees_images_for_active_tools(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $activeTool = $this->createTool($vendorProfile, $category);
        $activeTool->update(['status' => 'active']);
        $inactiveTool = $this->createTool($vendorProfile, $category);
        $inactiveTool->update(['status' => 'inactive']);
        $activeToolImage = ToolImage::create([
            'tool_id' => $activeTool->id,
            'image_path' => 'tool-images/active.jpg',
        ]);
        ToolImage::create([
            'tool_id' => $inactiveTool->id,
            'image_path' => 'tool-images/inactive.jpg',
        ]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->getJson('/api/v1/tool-images')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeToolImage->id)
            ->assertJsonPath('data.0.tool.id', $activeTool->id);
    }

    public function test_customer_cannot_view_image_for_non_active_tool(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $tool = $this->createTool($vendorProfile, $category);
        $tool->update(['status' => 'inactive']);
        $toolImage = ToolImage::create([
            'tool_id' => $tool->id,
            'image_path' => 'tool-images/inactive.jpg',
        ]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/tool-images/{$toolImage->id}")
            ->assertForbidden();
    }

    public function test_vendor_only_sees_images_for_own_unpublished_tools(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $otherVendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $ownPendingTool = $this->createTool($vendorProfile, $category);
        $ownPendingImage = ToolImage::create([
            'tool_id' => $ownPendingTool->id,
            'image_path' => 'tool-images/own-pending.jpg',
        ]);
        $otherActiveTool = $this->createTool($otherVendorProfile, $category);
        $otherActiveTool->update(['status' => 'active']);
        $otherActiveImage = ToolImage::create([
            'tool_id' => $otherActiveTool->id,
            'image_path' => 'tool-images/other-active.jpg',
        ]);
        $otherUnpublishedImages = collect(['pending', 'rejected', 'inactive'])
            ->map(function (string $status) use ($otherVendorProfile, $category): ToolImage {
                $tool = $this->createTool($otherVendorProfile, $category);
                $tool->update([
                    'address' => "Private {$status} image address",
                    'status' => $status,
                ]);

                return ToolImage::create([
                    'tool_id' => $tool->id,
                    'image_path' => "tool-images/other-{$status}.jpg",
                ]);
            });
        $token = $vendor->createToken('test-client')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/tool-images')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$ownPendingImage->id, $otherActiveImage->id],
            collect($response->json('data'))->pluck('id')->all(),
        );

        foreach ($otherUnpublishedImages as $toolImage) {
            $response->assertJsonMissing(['image_path' => $toolImage->image_path]);
            $response->assertJsonMissing(['address' => $toolImage->tool->address]);

            $this
                ->withToken($token)
                ->getJson("/api/v1/tool-images/{$toolImage->id}")
                ->assertForbidden();
        }

        $this
            ->withToken($token)
            ->getJson("/api/v1/tool-images/{$ownPendingImage->id}")
            ->assertOk()
            ->assertJsonPath('id', $ownPendingImage->id);
    }

    public function test_customer_cannot_view_another_customers_booking(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer);

        $response = $this
            ->withToken($otherCustomer->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/bookings/{$booking->id}");

        $response->assertForbidden();
    }

    public function test_customer_booking_index_is_scoped_to_own_bookings(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer);

        $this->createBooking(User::factory()->create(['role' => 'customer']));

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->getJson('/api/v1/bookings');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $booking->id);
    }

    public function test_customer_cannot_inject_booking_ownership_or_amounts(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $otherVendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $tool = $this->createTool($vendorProfile, $category);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'customer_id' => $customer->id,
                'vendor_id' => $otherVendorProfile->id,
                'start_at' => now()->addDay()->toDateTimeString(),
                'end_at' => now()->addDays(2)->toDateTimeString(),
                'rental_price' => 20,
                'total_amount' => 25,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'vendor_id', 'rental_price', 'total_amount']);

        $this->assertDatabaseMissing('bookings', [
            'tool_id' => $tool->id,
            'customer_id' => $customer->id,
            'vendor_id' => $otherVendorProfile->id,
        ]);
    }

    public function test_customer_payment_records_are_derived_from_the_booking(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => $booking->id,
                'provider' => 'demo',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('booking_id', $booking->id)
            ->assertJsonPath('customer_id', $customer->id)
            ->assertJsonPath('amount', '25.00')
            ->assertJsonPath('status', 'pending');
    }

    public function test_customer_can_view_own_payment_but_not_another_customers_payment(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $payment = $this->createPayment($this->createBooking($customer));
        $otherPayment = $this->createPayment($this->createBooking(User::factory()->create(['role' => 'customer'])));
        $token = $customer->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson("/api/v1/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('id', $payment->id);

        $this
            ->withToken($token)
            ->getJson("/api/v1/payments/{$otherPayment->id}")
            ->assertForbidden();
    }

    private function createVendorProfile(User $user): VendorProfile
    {
        return VendorProfile::create([
            'user_id' => $user->id,
            'business_name' => "{$user->name} Rentals",
            'verification_status' => 'approved',
        ]);
    }

    private function createTool(VendorProfile $vendorProfile, Category $category): Tool
    {
        return Tool::create($this->toolPayload($vendorProfile, $category));
    }

    private function createBooking(User $customer): Booking
    {
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::firstOrCreate(
            ['slug' => 'drills'],
            ['name' => 'Drills'],
        );
        $tool = $this->createTool($vendorProfile, $category);

        return Booking::create([
            'tool_id' => $tool->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendorProfile->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(2),
            'rental_price' => 20,
            'total_amount' => 25,
        ]);
    }

    private function createPayment(Booking $booking): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'amount' => $booking->total_amount,
        ]);
    }

    private function toolPayload(VendorProfile $vendorProfile, Category $category): array
    {
        return [
            'vendor_id' => $vendorProfile->id,
            'category_id' => $category->id,
            'title' => 'Cordless drill',
            'price_per_day' => 20,
            'city' => 'Vilnius',
        ];
    }
}
