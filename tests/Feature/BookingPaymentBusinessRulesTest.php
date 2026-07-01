<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Bookings\Models\Booking;
use Modules\Categories\Models\Category;
use Modules\LockCodes\Models\LockCode;
use Modules\Payments\Data\PaymentRefundResult;
use Modules\Payments\Jobs\ProcessPaymentRefund;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\StripePaymentService;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\PaymentIntent as StripePaymentIntent;
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

        $this->assertNotNull($response->json('expires_at'));
    }

    public function test_admin_can_create_booking_only_for_eligible_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $tool = Tool::factory()->create();
        $token = $admin->createToken('test-client')->plainTextToken;
        $startAt = now()->addDay();
        $payload = [
            'tool_id' => $tool->id,
            'start_at' => $startAt->toDateTimeString(),
            'end_at' => $startAt->copy()->addDay()->toDateTimeString(),
        ];
        $ineligibleCustomers = [
            User::factory()->admin()->create(),
            User::factory()->vendor()->create(),
            User::factory()->customer()->blocked()->create(),
            User::factory()->customer()->unverified()->create(),
        ];

        foreach ($ineligibleCustomers as $customer) {
            $this
                ->withToken($token)
                ->postJson('/api/v1/bookings', [
                    ...$payload,
                    'customer_id' => $customer->id,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('customer_id');
        }

        $customer = User::factory()->customer()->create();

        $this
            ->withToken($token)
            ->postJson('/api/v1/bookings', [
                ...$payload,
                'customer_id' => $customer->id,
            ])
            ->assertCreated()
            ->assertJsonPath('customer_id', $customer->id);
    }

    public function test_customer_cannot_book_non_active_tool(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $token = $customer->createToken('test-client')->plainTextToken;

        foreach (['pending', 'inactive', 'rejected'] as $status) {
            $tool = $this->createTool($vendorProfile, $category, status: $status);

            $this
                ->withToken($token)
                ->postJson('/api/v1/bookings', [
                    'tool_id' => $tool->id,
                    'start_at' => now()->addDay()->toDateTimeString(),
                    'end_at' => now()->addDays(2)->toDateTimeString(),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('tool_id');
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_customer_cannot_book_active_tool_from_unapproved_vendor(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $token = $customer->createToken('test-client')->plainTextToken;

        foreach (['pending', 'rejected'] as $verificationStatus) {
            $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
            $vendorProfile->update(['verification_status' => $verificationStatus]);
            $tool = $this->createTool($vendorProfile, $category, status: 'active');

            $this
                ->withToken($token)
                ->postJson('/api/v1/bookings', [
                    'tool_id' => $tool->id,
                    'start_at' => now()->addDay()->toDateTimeString(),
                    'end_at' => now()->addDays(2)->toDateTimeString(),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('tool_id');
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_tool_prices_are_bounded_to_supported_booking_amounts(): void
    {
        $vendor = User::factory()->vendor()->create();
        $vendorProfile = $this->createVendorProfile($vendor);
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/tools', [
                'vendor_id' => $vendorProfile->id,
                'category_id' => $category->id,
                'title' => 'Overflow drill',
                'price_per_day' => Tool::MaxPricePerDay + 1,
                'deposit_amount' => Tool::MaxDepositAmount + 1,
                'city' => 'Vilnius',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_per_day', 'deposit_amount']);

        $this->assertDatabaseCount('tools', 0);
    }

    public function test_booking_rejects_rental_period_longer_than_supported_maximum(): void
    {
        $customer = User::factory()->customer()->create();
        $tool = Tool::factory()->create();
        $startAt = now()->addDay();

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'start_at' => $startAt->toDateTimeString(),
                'end_at' => $startAt->copy()->addDays(Booking::MaxRentalDays + 1)->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_at');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_service_rejects_existing_tool_price_that_overflows_schema(): void
    {
        $customer = User::factory()->customer()->create();
        $tool = Tool::factory()->create([
            'price_per_day' => Booking::MaxMoneyAmount,
        ]);
        $startAt = now()->addDay();

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'start_at' => $startAt->toDateTimeString(),
                'end_at' => $startAt->copy()->addDays(2)->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tool_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    #[DataProvider('ineligibleVendorAccountProvider')]
    public function test_customer_cannot_view_or_book_tool_from_ineligible_vendor_account(array $attributes): void
    {
        $vendor = User::factory()->create([
            'role' => 'vendor',
            'status' => 'active',
            'email_verified_at' => now(),
            ...$attributes,
        ]);
        $vendorProfile = $this->createVendorProfile($vendor);
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $tool = $this->createTool($vendorProfile, $category);
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/tools')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this
            ->withToken($token)
            ->getJson("/api/v1/tools/{$tool->id}")
            ->assertForbidden();

        $this
            ->withToken($token)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'start_at' => now()->addDay()->toDateTimeString(),
                'end_at' => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tool_id');
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

    public function test_expired_pending_bookings_do_not_block_availability_and_are_cancelled(): void
    {
        $customer = User::factory()->customer()->create();
        $vendorProfile = $this->createVendorProfile(User::factory()->vendor()->create());
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);
        $tool = $this->createTool($vendorProfile, $category);
        $expiredBooking = $this->createBooking($customer, $tool, $vendorProfile, [
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(3),
            'expires_at' => now()->subMinute(),
        ]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'start_at' => now()->addDays(2)->toDateTimeString(),
                'end_at' => now()->addDays(4)->toDateTimeString(),
            ])
            ->assertCreated();

        $this->artisan('bookings:expire-pending')
            ->expectsOutput('Expired 1 pending bookings.')
            ->assertSuccessful();

        $this->assertSame('cancelled', $expiredBooking->refresh()->status);
    }

    public function test_customer_cannot_exceed_pending_booking_limit(): void
    {
        config()->set('bookings.max_pending_per_customer', 2);

        $customer = User::factory()->customer()->create();
        $vendorProfile = $this->createVendorProfile(User::factory()->vendor()->create());
        $category = Category::create(['name' => 'Drills', 'slug' => 'drills']);

        foreach (range(1, 2) as $index) {
            $this->createBooking(
                $customer,
                $this->createTool($vendorProfile, $category),
                $vendorProfile,
            );
        }

        $tool = $this->createTool($vendorProfile, $category);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/bookings', [
                'tool_id' => $tool->id,
                'start_at' => now()->addDay()->toDateTimeString(),
                'end_at' => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tool_id');
    }

    public function test_expired_pending_booking_cannot_be_paid(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = $this->createBooking($customer, attributes: [
            'expires_at' => now()->subMinute(),
        ]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => $booking->id,
                'provider' => 'demo',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking_id');
    }

    public function test_expired_pending_booking_payment_cannot_be_marked_paid(): void
    {
        $booking = $this->createBooking(User::factory()->customer()->create(), attributes: [
            'expires_at' => now()->subMinute(),
        ]);

        $payment = $this->createPayment($booking);
        $admin = User::factory()->admin()->create();

        $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", ['status' => 'paid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
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

    public function test_customer_can_soft_delete_safe_pending_booking(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->deleteJson("/api/v1/bookings/{$booking->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($booking);
    }

    public function test_customer_and_vendor_cannot_delete_paid_booking_or_its_audit_records(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking($customer, vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, ['status' => 'paid']);
        $lockCode = LockCode::create([
            'booking_id' => $booking->id,
            'code' => '482951',
            'valid_from' => $booking->start_at,
            'valid_until' => $booking->end_at,
        ]);

        foreach ([$customer, $vendor] as $user) {
            $this
                ->withToken($user->createToken('test-client')->plainTextToken)
                ->deleteJson("/api/v1/bookings/{$booking->id}")
                ->assertForbidden();
        }

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'paid',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('lock_codes', ['id' => $lockCode->id]);
    }

    public function test_pending_booking_with_payment_record_cannot_be_deleted(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $booking = $this->createBooking($customer);
        $payment = $this->createPayment($booking);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->deleteJson("/api/v1/bookings/{$booking->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_payment_audit_records_cannot_be_deleted_by_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->createBooking(User::factory()->customer()->create(), attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, ['status' => 'paid']);

        $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->deleteJson("/api/v1/payments/{$payment->id}")
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_tool_and_vendor_with_booking_history_cannot_be_deleted(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking($customer, vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, ['status' => 'paid']);
        $lockCode = LockCode::create([
            'booking_id' => $booking->id,
            'code' => '482951',
            'valid_from' => $booking->start_at,
            'valid_until' => $booking->end_at,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([$vendor, $admin] as $user) {
            $token = $user->createToken('test-client')->plainTextToken;

            $this
                ->withToken($token)
                ->deleteJson("/api/v1/tools/{$booking->tool_id}")
                ->assertForbidden();

            $this
                ->withToken($token)
                ->deleteJson("/api/v1/vendors/{$vendorProfile->id}")
                ->assertForbidden();
        }

        foreach ([$customer, $vendor] as $user) {
            $this
                ->withToken($admin->createToken('test-client')->plainTextToken)
                ->deleteJson("/api/v1/users/{$user->id}")
                ->assertForbidden();
        }

        $this->assertDatabaseHas('tools', [
            'id' => $booking->tool_id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('vendor_profiles', [
            'id' => $vendorProfile->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'paid',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('lock_codes', ['id' => $lockCode->id]);
    }

    public function test_soft_deleted_booking_history_still_prevents_tool_and_vendor_deletion(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking($customer, vendorProfile: $vendorProfile);

        $booking->delete();

        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/tools/{$booking->tool_id}")
            ->assertForbidden();

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/vendors/{$vendorProfile->id}")
            ->assertForbidden();

        $this->assertSoftDeleted($booking);
        $this->assertNotSoftDeleted($booking->tool);
        $this->assertNotSoftDeleted($vendorProfile);
    }

    public function test_restricted_foreign_keys_protect_paid_audit_records_from_hard_deletes(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $vendorProfile = $this->createVendorProfile(User::factory()->create(['role' => 'vendor']));
        $booking = $this->createBooking($customer, vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, ['status' => 'paid']);
        $lockCode = LockCode::create([
            'booking_id' => $booking->id,
            'code' => '482951',
            'valid_from' => $booking->start_at,
            'valid_until' => $booking->end_at,
        ]);

        foreach ([
            ['tools', $booking->tool_id],
            ['vendor_profiles', $vendorProfile->id],
            ['bookings', $booking->id],
            ['users', $customer->id],
            ['users', $vendorProfile->user_id],
        ] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail("Expected the {$table} foreign key to restrict deletion.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseHas('tools', ['id' => $booking->tool_id]);
        $this->assertDatabaseHas('vendor_profiles', ['id' => $vendorProfile->id]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('lock_codes', ['id' => $lockCode->id]);
    }

    public function test_vendor_can_progress_paid_booking_to_active_and_completed(): void
    {
        $this->travelTo(now()->startOfSecond());

        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), vendorProfile: $vendorProfile, attributes: [
            'start_at' => now()->subMinute(),
            'end_at' => now()->addMinute(),
            'status' => 'paid',
        ]);
        $this->createPayment($booking, ['status' => 'paid']);
        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this->travelTo($booking->end_at->copy()->addSecond());

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_vendor_cannot_progress_booking_outside_its_rental_window(): void
    {
        $this->travelTo(now()->startOfSecond());

        $vendor = User::factory()->vendor()->create();
        $vendorProfile = $this->createVendorProfile($vendor);
        $customer = User::factory()->customer()->create();
        $token = $vendor->createToken('test-client')->plainTextToken;
        $futureBooking = $this->createBooking($customer, vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $this->createPayment($futureBooking, ['status' => 'paid']);

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$futureBooking->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $activeBooking = $this->createBooking($customer, vendorProfile: $vendorProfile, attributes: [
            'start_at' => now()->subMinute(),
            'end_at' => now()->addMinute(),
            'status' => 'active',
        ]);
        $this->createPayment($activeBooking, ['status' => 'paid']);

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$activeBooking->id}", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $expiredPaidBooking = $this->createBooking($customer, vendorProfile: $vendorProfile, attributes: [
            'start_at' => now()->subMinutes(2),
            'end_at' => now()->subMinute(),
            'status' => 'paid',
        ]);
        $this->createPayment($expiredPaidBooking, ['status' => 'paid']);

        $this
            ->withToken($token)
            ->patchJson("/api/v1/bookings/{$expiredPaidBooking->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_vendor_cancelling_paid_booking_refunds_payment(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, ['status' => 'paid']);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment.status', 'refunded');

        $this->assertSame('cancelled', $booking->refresh()->status);
        $this->assertSame('refunded', $payment->refresh()->status);
    }

    public function test_stripe_booking_cancellation_only_marks_confirmed_refund_as_refunded(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_confirmed_refund',
            'status' => 'paid',
        ]);
        $stripe = Mockery::mock(StripePaymentService::class);
        $transactionLevel = DB::transactionLevel();

        $stripe
            ->shouldReceive('createRefund')
            ->once()
            ->withArgs(fn (Payment $candidate): bool => $candidate->is($payment)
                && DB::transactionLevel() === $transactionLevel)
            ->andReturn(new PaymentRefundResult('refunded', 're_confirmed'));
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment.status', 'refunded')
            ->assertJsonPath('payment.provider_refund_id', 're_confirmed');
    }

    public function test_stripe_refund_is_queued_after_booking_cancellation_commits(): void
    {
        Queue::fake();

        $vendor = User::factory()->vendor()->create();
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->customer()->create(), vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_queued_refund',
            'status' => 'paid',
        ]);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment.status', 'refund_pending');

        Queue::assertPushed(
            ProcessPaymentRefund::class,
            fn (ProcessPaymentRefund $job): bool => $job->paymentId === $payment->id
                && $job->refundAttempt === 1,
        );
        $this->assertSame(1, $payment->refresh()->refund_attempts);
    }

    public function test_terminal_refund_job_failure_marks_only_its_attempt_as_failed(): void
    {
        $booking = $this->createBooking(User::factory()->customer()->create(), attributes: [
            'status' => 'cancelled',
        ]);
        $payment = $this->createPayment($booking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_failed_job',
            'refund_attempts' => 2,
            'status' => 'refund_pending',
        ]);

        (new ProcessPaymentRefund($payment->id, 1))->failed(null);

        $this->assertSame('refund_pending', $payment->refresh()->status);

        (new ProcessPaymentRefund($payment->id, 2))->failed(null);

        $this->assertSame('refund_failed', $payment->refresh()->status);
    }

    public function test_pending_stripe_refund_is_not_reported_as_refunded(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_pending_refund',
            'status' => 'paid',
        ]);
        $stripe = Mockery::mock(StripePaymentService::class);

        $stripe
            ->shouldReceive('createRefund')
            ->once()
            ->andReturn(new PaymentRefundResult('refund_pending', 're_pending'));
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment.status', 'refund_pending')
            ->assertJsonPath('payment.provider_refund_id', 're_pending');
    }

    public function test_failed_stripe_refund_is_recorded_for_retry(): void
    {
        $vendor = User::factory()->vendor()->create();
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->customer()->create(), vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_failed_refund',
            'status' => 'paid',
        ]);
        $stripe = Mockery::mock(StripePaymentService::class);

        $stripe
            ->shouldReceive('createRefund')
            ->once()
            ->andReturn(new PaymentRefundResult('refund_failed', 're_failed'));
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment.status', 'refund_failed')
            ->assertJsonPath('payment.provider_refund_id', 're_failed');

        $this->assertSame('refund_failed', $payment->refresh()->status);
        $this->assertSame(1, $payment->refund_attempts);
    }

    public function test_pending_stripe_refunds_are_synchronized_after_confirmation(): void
    {
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), attributes: [
            'status' => 'cancelled',
        ]);
        $payment = $this->createPayment($booking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_pending_refund',
            'provider_refund_id' => 're_pending',
            'status' => 'refund_pending',
        ]);
        $stripe = Mockery::mock(StripePaymentService::class);

        $stripe
            ->shouldReceive('retrieveRefund')
            ->once()
            ->with('re_pending')
            ->andReturn(new PaymentRefundResult('refunded', 're_pending'));
        $this->app->instance(StripePaymentService::class, $stripe);

        $this->artisan('payments:sync-stripe-refunds')
            ->expectsOutput('Synchronized 1 Stripe refunds.')
            ->assertSuccessful();

        $this->assertSame('refunded', $payment->refresh()->status);
    }

    public function test_admin_can_retry_failed_stripe_refund(): void
    {
        $booking = $this->createBooking(User::factory()->customer()->create(), attributes: [
            'status' => 'cancelled',
        ]);
        $payment = $this->createPayment($booking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_failed_refund',
            'provider_refund_id' => 're_failed',
            'refund_attempts' => 1,
            'status' => 'refund_failed',
        ]);
        $stripe = Mockery::mock(StripePaymentService::class);

        $stripe
            ->shouldReceive('createRefund')
            ->once()
            ->withArgs(fn (Payment $candidate): bool => $candidate->is($payment))
            ->andReturn(new PaymentRefundResult('refunded', 're_retry'));
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken(User::factory()->admin()->create()->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", ['status' => 'refunded'])
            ->assertOk()
            ->assertJsonPath('status', 'refunded')
            ->assertJsonPath('provider_refund_id', 're_retry')
            ->assertJsonPath('refund_attempts', 2)
            ->assertJsonPath('booking.status', 'cancelled');
    }

    public function test_refunding_payment_cancels_booking_and_prevents_activation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, ['status' => 'paid']);

        $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", ['status' => 'refunded'])
            ->assertOk()
            ->assertJsonPath('status', 'refunded')
            ->assertJsonPath('booking.status', 'cancelled');

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('cancelled', $booking->refresh()->status);
        $this->assertSame('refunded', $payment->refresh()->status);
    }

    public function test_booking_requires_paid_payment_before_activation(): void
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $vendorProfile = $this->createVendorProfile($vendor);
        $booking = $this->createBooking(User::factory()->create(['role' => 'customer']), vendorProfile: $vendorProfile, attributes: [
            'status' => 'paid',
        ]);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/bookings/{$booking->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('paid', $booking->refresh()->status);
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

    public function test_stripe_payment_requires_payment_intent_before_being_marked_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->createBooking(User::factory()->customer()->create());
        $payment = $this->createPayment($booking, ['provider' => 'stripe']);

        $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", ['status' => 'paid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider_payment_id');

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame('pending', $booking->refresh()->status);
    }

    public function test_stripe_payment_is_verified_before_being_marked_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->createBooking(User::factory()->customer()->create());
        $payment = $this->createPayment($booking, ['provider' => 'stripe']);
        $paymentIntent = StripePaymentIntent::constructFrom([
            'id' => 'pi_verified',
            'status' => StripePaymentIntent::STATUS_SUCCEEDED,
            'amount_received' => 2500,
            'currency' => 'eur',
            'metadata' => [
                'booking_id' => (string) $booking->id,
                'payment_id' => (string) $payment->id,
                'customer_id' => (string) $booking->customer_id,
            ],
        ]);
        $stripe = new class($paymentIntent) extends StripePaymentService
        {
            public function __construct(
                private StripePaymentIntent $paymentIntent,
            ) {}

            protected function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntent
            {
                return $this->paymentIntent;
            }
        };
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", [
                'status' => 'paid',
                'provider_payment_id' => 'pi_verified',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertSame('paid', $booking->refresh()->status);
    }

    public function test_mismatched_stripe_payment_cannot_be_marked_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->createBooking(User::factory()->customer()->create());
        $payment = $this->createPayment($booking, ['provider' => 'stripe']);
        $paymentIntent = StripePaymentIntent::constructFrom([
            'id' => 'pi_wrong_amount',
            'status' => StripePaymentIntent::STATUS_SUCCEEDED,
            'amount_received' => 100,
            'currency' => 'eur',
            'metadata' => [
                'booking_id' => (string) $booking->id,
                'payment_id' => (string) $payment->id,
                'customer_id' => (string) $booking->customer_id,
            ],
        ]);
        $stripe = new class($paymentIntent) extends StripePaymentService
        {
            public function __construct(
                private StripePaymentIntent $paymentIntent,
            ) {}

            protected function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntent
            {
                return $this->paymentIntent;
            }
        };
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", [
                'status' => 'paid',
                'provider_payment_id' => 'pi_wrong_amount',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider_payment_id');

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame('pending', $booking->refresh()->status);
    }

    public function test_provider_payment_reference_cannot_be_reused(): void
    {
        $customer = User::factory()->customer()->create();
        $firstBooking = $this->createBooking($customer);
        $secondBooking = $this->createBooking($customer);
        $this->createPayment($firstBooking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_unique',
        ]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => $secondBooking->id,
                'provider' => 'stripe',
                'provider_payment_id' => 'pi_unique',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider_payment_id');
    }

    public function test_paid_provider_payment_reference_cannot_be_replaced(): void
    {
        $admin = User::factory()->admin()->create();
        $booking = $this->createBooking(User::factory()->customer()->create(), attributes: [
            'status' => 'paid',
        ]);
        $payment = $this->createPayment($booking, [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_original',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $paymentIntent = StripePaymentIntent::constructFrom([
            'id' => 'pi_replacement',
            'status' => StripePaymentIntent::STATUS_SUCCEEDED,
            'amount_received' => 2500,
            'currency' => 'eur',
            'metadata' => [
                'booking_id' => (string) $booking->id,
                'payment_id' => (string) $payment->id,
                'customer_id' => (string) $booking->customer_id,
            ],
        ]);
        $stripe = new class($paymentIntent) extends StripePaymentService
        {
            public function __construct(
                private StripePaymentIntent $paymentIntent,
            ) {}

            protected function retrievePaymentIntent(string $paymentIntentId): StripePaymentIntent
            {
                return $this->paymentIntent;
            }
        };
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken($admin->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/payments/{$payment->id}", [
                'status' => 'paid',
                'provider_payment_id' => 'pi_replacement',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider_payment_id');

        $this->assertSame('pi_original', $payment->refresh()->provider_payment_id);
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

    public static function ineligibleVendorAccountProvider(): array
    {
        return [
            'blocked account' => [['status' => 'blocked']],
            'pending account' => [['status' => 'pending']],
            'non-vendor account' => [['role' => 'customer']],
            'unverified account' => [['email_verified_at' => null]],
        ];
    }

    private function createVendorProfile(User $user): VendorProfile
    {
        return VendorProfile::create([
            'user_id' => $user->id,
            'business_name' => "{$user->name} Rentals",
            'verification_status' => 'approved',
        ]);
    }

    private function createTool(
        VendorProfile $vendorProfile,
        Category $category,
        float $pricePerDay = 20,
        float $depositAmount = 5,
        string $status = 'active',
    ): Tool {
        return Tool::create([
            'vendor_id' => $vendorProfile->id,
            'category_id' => $category->id,
            'title' => 'Cordless drill',
            'price_per_day' => $pricePerDay,
            'deposit_amount' => $depositAmount,
            'city' => 'Vilnius',
            'status' => $status,
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
