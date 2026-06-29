<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Bookings\Models\Booking;
use Modules\LockCodes\Models\LockCode;
use Modules\Tools\Models\Tool;
use Modules\Vendors\Models\VendorProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LockCodeSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_lock_code_is_encrypted_at_rest(): void
    {
        $lockCode = LockCode::factory()->create([
            'code' => '123456',
        ]);

        $storedCode = DB::table('lock_codes')
            ->where('id', $lockCode->id)
            ->value('code');

        $this->assertNotSame('123456', $storedCode);
        $this->assertSame('123456', $lockCode->refresh()->code);
    }

    public function test_lock_code_index_and_show_responses_do_not_include_code(): void
    {
        [$vendor, $booking] = $this->createVendorBooking();
        $lockCode = LockCode::factory()->active()->create([
            'booking_id' => $booking->id,
            'code' => '123456',
        ]);
        $token = $vendor->createToken('test-client')->plainTextToken;

        $indexResponse = $this
            ->withToken($token)
            ->getJson('/api/v1/lock-codes');

        $indexResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lockCode->id);

        $this->assertArrayNotHasKey('code', $indexResponse->json('data.0'));

        $showResponse = $this
            ->withToken($token)
            ->getJson("/api/v1/lock-codes/{$lockCode->id}");

        $showResponse
            ->assertOk()
            ->assertJsonPath('id', $lockCode->id);

        $this->assertArrayNotHasKey('code', $showResponse->json());
    }

    public function test_customer_can_reveal_active_valid_lock_code_and_access_is_audited(): void
    {
        [, $booking, $customer] = $this->createVendorBooking();
        $lockCode = LockCode::factory()->active()->create([
            'booking_id' => $booking->id,
            'code' => '123456',
            'valid_from' => now()->subMinute(),
            'valid_until' => now()->addMinute(),
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Lock code revealed.', Mockery::on(fn (array $context) => $context === [
                'lock_code_id' => $lockCode->id,
                'booking_id' => $booking->id,
                'user_id' => $customer->id,
                'user_role' => 'customer',
            ]));

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/lock-codes/{$lockCode->id}/reveal")
            ->assertOk()
            ->assertJsonPath('code', '123456');
    }

    public function test_customer_cannot_reveal_lock_code_before_it_is_active_and_valid(): void
    {
        [, $booking, $customer] = $this->createVendorBooking();
        $lockCode = LockCode::factory()->create([
            'booking_id' => $booking->id,
            'code' => '123456',
            'status' => 'generated',
            'valid_from' => now()->addHour(),
            'valid_until' => now()->addHours(2),
        ]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/lock-codes/{$lockCode->id}/reveal")
            ->assertForbidden();
    }

    public function test_vendor_can_reveal_own_active_valid_lock_code_during_active_rental(): void
    {
        [$vendor, $booking] = $this->createVendorBooking();
        $lockCode = LockCode::factory()->active()->create([
            'booking_id' => $booking->id,
            'code' => '123456',
            'valid_from' => now()->subMinute(),
            'valid_until' => now()->addMinute(),
        ]);

        Log::shouldReceive('info')->once();

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/lock-codes/{$lockCode->id}/reveal")
            ->assertOk()
            ->assertJsonPath('code', '123456');
    }

    #[DataProvider('ineligibleBookingProvider')]
    public function test_vendor_cannot_reveal_active_lock_code_for_ineligible_booking(
        string $status,
        bool $futureRental,
    ): void {
        [$vendor, $booking] = $this->createVendorBooking();
        $booking->update(['status' => $status]);

        if ($futureRental) {
            $booking->update([
                'start_at' => now()->addDay(),
                'end_at' => now()->addDays(2),
            ]);
        }

        $lockCode = LockCode::factory()->active()->create([
            'booking_id' => $booking->id,
            'valid_from' => now()->subMinute(),
            'valid_until' => now()->addMinute(),
        ]);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->getJson("/api/v1/lock-codes/{$lockCode->id}/reveal")
            ->assertForbidden();
    }

    #[DataProvider('ineligibleBookingProvider')]
    public function test_vendor_cannot_activate_lock_code_for_ineligible_booking(
        string $status,
        bool $futureRental,
    ): void {
        [$vendor, $booking] = $this->createVendorBooking();
        $booking->update(['status' => $status]);

        if ($futureRental) {
            $booking->update([
                'start_at' => now()->addDay(),
                'end_at' => now()->addDays(2),
            ]);
        }

        $lockCode = LockCode::factory()->create([
            'booking_id' => $booking->id,
            'valid_from' => $booking->start_at,
            'valid_until' => $booking->end_at,
        ]);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/lock-codes/{$lockCode->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_vendor_can_activate_lock_code_during_active_booking_rental(): void
    {
        [$vendor, $booking] = $this->createVendorBooking();
        $lockCode = LockCode::factory()->create([
            'booking_id' => $booking->id,
            'valid_from' => $booking->start_at,
            'valid_until' => $booking->end_at,
        ]);

        $this
            ->withToken($vendor->createToken('test-client')->plainTextToken)
            ->patchJson("/api/v1/lock-codes/{$lockCode->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('status', 'active');
    }

    public function test_lock_code_validity_must_stay_within_booking_rental_window(): void
    {
        [$vendor, $booking] = $this->createVendorBooking();
        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/lock-codes', [
                'booking_id' => $booking->id,
                'code' => '123456',
                'valid_from' => $booking->start_at->copy()->subMinute()->toDateTimeString(),
                'valid_until' => $booking->end_at->copy()->addMinute()->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['valid_from', 'valid_until']);

        $lockCode = LockCode::factory()->create([
            'booking_id' => $booking->id,
            'valid_from' => $booking->start_at,
            'valid_until' => $booking->end_at,
        ]);

        $this
            ->withToken($token)
            ->patchJson("/api/v1/lock-codes/{$lockCode->id}", [
                'valid_until' => $booking->end_at->copy()->addMinute()->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_until');
    }

    public function test_partial_lock_code_updates_preserve_valid_interval(): void
    {
        [$vendor, $booking] = $this->createVendorBooking();
        $validFrom = now()->addDay()->startOfSecond();
        $validUntil = $validFrom->copy()->addDay();
        $lockCode = LockCode::factory()->create([
            'booking_id' => $booking->id,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
        ]);
        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->patchJson("/api/v1/lock-codes/{$lockCode->id}", [
                'valid_from' => $validUntil->copy()->addMinute()->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_from');

        $this
            ->withToken($token)
            ->patchJson("/api/v1/lock-codes/{$lockCode->id}", [
                'valid_until' => $validFrom->copy()->subMinute()->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_until');

        $lockCode->refresh();

        $this->assertTrue($lockCode->valid_from->equalTo($validFrom));
        $this->assertTrue($lockCode->valid_until->equalTo($validUntil));
    }

    public function test_lock_code_requests_reject_malformed_booking_ids_with_validation_errors(): void
    {
        [$vendor, $booking] = $this->createVendorBooking();
        $lockCode = LockCode::factory()->create([
            'booking_id' => $booking->id,
        ]);
        $token = $vendor->createToken('test-client')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/lock-codes', [
                'booking_id' => [],
                'code' => '123456',
                'valid_from' => $booking->start_at->toDateTimeString(),
                'valid_until' => $booking->end_at->toDateTimeString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking_id');

        $this
            ->withToken($token)
            ->patchJson("/api/v1/lock-codes/{$lockCode->id}", [
                'booking_id' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking_id');
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ineligibleBookingProvider(): array
    {
        return [
            'pending booking' => ['pending', false],
            'paid booking' => ['paid', false],
            'cancelled booking' => ['cancelled', false],
            'completed booking' => ['completed', false],
            'future active booking' => ['active', true],
        ];
    }

    private function createVendorBooking(): array
    {
        $vendor = User::factory()->vendor()->create();
        $customer = User::factory()->customer()->create();
        $vendorProfile = VendorProfile::factory()->create([
            'user_id' => $vendor->id,
        ]);
        $tool = Tool::factory()->create([
            'vendor_id' => $vendorProfile->id,
        ]);
        $booking = Booking::factory()->active()->create([
            'tool_id' => $tool->id,
            'customer_id' => $customer->id,
            'vendor_id' => $vendorProfile->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(3),
        ]);

        return [$vendor, $booking, $customer];
    }
}
