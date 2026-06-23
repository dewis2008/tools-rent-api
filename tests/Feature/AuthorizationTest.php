<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\Payments\Models\Payment;
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
