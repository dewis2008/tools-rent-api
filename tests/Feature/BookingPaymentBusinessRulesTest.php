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

class BookingPaymentBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_booking_derives_customer_vendor_and_amounts(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $tool = $this->createTool($vendorProfile, $category, pricePerDay: 20, depositAmount: 10);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'start_at' => now()->addDay()->toDateTimeString(),
                'end_at' => now()->addDays(3)->toDateTimeString(),
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('tool_id', $tool->id)
            ->assertJsonPath('customer_id', $customer->id)
            ->assertJsonPath('vendor_id', $vendorProfile->id)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('rental_price', '40.00')
            ->assertJsonPath('deposit_amount', '10.00')
            ->assertJsonPath('platform_fee', '4.00')
            ->assertJsonPath('vendor_amount', '36.00')
            ->assertJsonPath('total_amount', '50.00');
    }

    public function test_booking_rejects_overlapping_active_reservations(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $tool = $this->createTool($vendorProfile, $category);

        $this->createBooking($customer, $tool, $vendorProfile, [
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(3),
            'status' => 'pending',
        ]);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'start_at' => now()->addDays(2)->toDateTimeString(),
                'end_at' => now()->addDays(4)->toDateTimeString(),
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_at');
    }

    public function test_cancelled_bookings_do_not_block_availability(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $tool = $this->createTool($vendorProfile, $category);

        $this->createBooking($customer, $tool, $vendorProfile, [
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(3),
            'status' => 'cancelled',
        ]);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'start_at' => now()->addDays(2)->toDateTimeString(),
                'end_at' => now()->addDays(4)->toDateTimeString(),
            ]);

        $response->assertCreated();
    }

    public function test_customer_can_only_cancel_pending_booking(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer);
        $token = $customer->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'paid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_vendor_can_progress_paid_booking_to_active_and_completed(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_customer_cannot_pay_another_customers_booking(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($otherCustomer);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => $booking->id,
                'provider' => 'demo',
            ]);

        $response->assertForbidden();
    }

    public function test_payment_create_rejects_malformed_booking_id_with_validation_error(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => [],
                'provider' => 'demo',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking_id');
    }

    public function test_payment_create_derives_customer_amount_currency_and_status(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer, attributes: [
            'total_amount' => 75,
        ]);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => $booking->id,
                'provider' => 'demo',
                'provider_payment_id' => 'demo-123',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('booking_id', $booking->id)
            ->assertJsonPath('customer_id', $customer->id)
            ->assertJsonPath('provider', 'demo')
            ->assertJsonPath('provider_payment_id', 'demo-123')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount', '75.00')
            ->assertJsonPath('currency', 'EUR');
    }

    public function test_payment_rejects_client_supplied_amount_and_customer(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer);

        $response = $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => $booking->id,
                'customer_id' => User::factory()->create(['role' => 'customer'])->id,
                'amount' => 1,
                'currency' => 'USD',
                'status' => 'paid',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'amount', 'currency', 'status']);
    }

    public function test_marking_payment_paid_marks_booking_paid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']));
        $payment = $this->createPayment($booking);

        $response = $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", [
                'status' => 'paid',
                'provider_payment_id' => 'paid-123',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('provider_payment_id', 'paid-123');

        $this->assertSame('paid', $booking->refresh()->status);
        $this->assertNotNull($payment->refresh()->paid_at);
    }

    public function test_invalid_payment_status_transition_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, ['status' => 'paid']);

        $response = $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", [
                'status' => 'failed',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    private function createVendorProfile(User $user): VendorProfile
    {
        return VendorProfile::create([
            'user_id' => $user->id,
            'business_name' => "{$user->name} Rentals",
        ]);
    }

    private function createTool(
        VendorProfile $vendorProfile,
        Category $category,
        float $pricePerDay = 20,
        float $depositAmount = 5,
    ): Tool {
        return Tool::create([
            'vendor_id' => $vendorProfile->id,
            'category_id' => $category->id,
            'title' => 'Cordless drill',
            'price_per_day' => $pricePerDay,
            'deposit_amount' => $depositAmount,
            'city' => 'Vilnius',
        ]);
    }

    private function createBooking(
        User $customer,
        ?Tool $tool = null,
        ?VendorProfile $vendorProfile = null,
        array $attributes = [],
    ): Booking {
        $vendorProfile ??= $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::firstOrCreate(
            ['slug' => 'drills'],
            ['name' => 'Drills'],
        );
        $tool ??= $this->createTool($vendorProfile, $category);

        return Booking::create([
            'tool_id' => $tool->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendorProfile->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(2),
            'status' => 'pending',
            'rental_price' => 20,
            'deposit_amount' => 5,
            'platform_fee' => 2,
            'vendor_amount' => 18,
            'total_amount' => 25,
            ...$attributes,
        ]);
    }

    private function createPayment(Booking $booking, array $attributes = []): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'amount' => $booking->total_amount,
            'status' => 'pending',
            ...$attributes,
        ]);
    }
}
