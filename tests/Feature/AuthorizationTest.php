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

    public function test_non_admin_cannot_create_payment_records(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => $booking->id,
                'customer_id' => $customer->id,
                'amount' => 25,
            ]);

        $response->assertForbidden();
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
