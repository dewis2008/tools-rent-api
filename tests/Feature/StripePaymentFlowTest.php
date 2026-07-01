<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Bookings\Models\Booking;
use Modules\Payments\Jobs\ProcessPaymentRefund;
use Modules\Payments\Models\Payment;
use Modules\Payments\Services\StripePaymentService;
use Stripe\Event;
use Stripe\PaymentIntent as StripePaymentIntent;
use Tests\TestCase;

class StripePaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_payment_intent_for_own_stripe_payment(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'total_amount' => 25,
        ]);
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'amount' => 25,
        ]);
        $paymentIntent = $this->paymentIntent($payment, [
            'status' => StripePaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
            'client_secret' => 'pi_client_secret_test',
        ]);
        $stripe = new class($paymentIntent) extends StripePaymentService
        {
            public function __construct(
                private StripePaymentIntent $paymentIntent,
            ) {}

            protected function createPaymentIntentOnStripe(Payment $payment): StripePaymentIntent
            {
                return $this->paymentIntent;
            }
        };
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson("/api/v1/payments/{$payment->id}/stripe-payment-intent")
            ->assertOk()
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment.provider_payment_id', $paymentIntent->id)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('client_secret', 'pi_client_secret_test')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache');

        $this->assertSame($paymentIntent->id, $payment->refresh()->provider_payment_id);
    }

    public function test_customer_cannot_create_payment_intent_for_another_customers_payment(): void
    {
        $owner = User::factory()->customer()->create();
        $payment = Payment::factory()->create([
            'booking_id' => Booking::factory()->create(['customer_id' => $owner->id])->id,
            'customer_id' => $owner->id,
            'provider' => 'stripe',
        ]);

        $this
            ->withToken(User::factory()->customer()->create()->createToken('test-client')->plainTextToken)
            ->postJson("/api/v1/payments/{$payment->id}/stripe-payment-intent")
            ->assertForbidden();

        $this->assertNull($payment->refresh()->provider_payment_id);
    }

    public function test_canceled_payment_intent_is_replaced_with_a_new_attempt(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'total_amount' => 25,
        ]);
        $payment = Payment::factory()->create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_canceled',
            'status' => 'failed',
            'amount' => 25,
        ]);
        $canceledIntent = $this->paymentIntent($payment, [
            'id' => 'pi_canceled',
            'status' => StripePaymentIntent::STATUS_CANCELED,
        ]);
        $replacementIntent = $this->paymentIntent($payment, [
            'id' => 'pi_replacement',
            'client_secret' => 'pi_replacement_secret',
        ]);
        $stripe = new class($canceledIntent, $replacementIntent) extends StripePaymentService
        {
            private int $calls = 0;

            public function __construct(
                private StripePaymentIntent $canceledIntent,
                private StripePaymentIntent $replacementIntent,
            ) {}

            public function createOrRetrievePaymentIntent(Payment $payment): StripePaymentIntent
            {
                return $this->calls++ === 0
                    ? $this->canceledIntent
                    : $this->replacementIntent;
            }
        };
        $this->app->instance(StripePaymentService::class, $stripe);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson("/api/v1/payments/{$payment->id}/stripe-payment-intent")
            ->assertOk()
            ->assertJsonPath('payment.provider_payment_id', 'pi_replacement')
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('client_secret', 'pi_replacement_secret');

        $payment->refresh();

        $this->assertSame(2, $payment->provider_payment_attempt);
        $this->assertSame('pi_replacement', $payment->provider_payment_id);
    }

    public function test_customer_cannot_supply_stripe_payment_intent_identifier(): void
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        $this
            ->withToken($customer->createToken('test-client')->plainTextToken)
            ->postJson('/api/v1/payments', [
                'booking_id' => $booking->id,
                'provider' => 'stripe',
                'provider_payment_id' => 'pi_client_supplied',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider_payment_id');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_signed_payment_intent_webhook_marks_payment_and_booking_paid(): void
    {
        $payment = $this->stripePayment();
        $paymentIntent = $this->paymentIntent($payment, [
            'status' => StripePaymentIntent::STATUS_SUCCEEDED,
            'amount_received' => 2500,
        ]);
        $payment->update(['provider_payment_id' => $paymentIntent->id]);

        $this
            ->postStripeWebhook(Event::PAYMENT_INTENT_SUCCEEDED, $paymentIntent->toArray())
            ->assertNoContent();

        $this->assertSame('paid', $payment->refresh()->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('paid', $payment->booking->refresh()->status);
    }

    public function test_invalid_stripe_webhook_signature_is_rejected(): void
    {
        $payment = $this->stripePayment();
        $paymentIntent = $this->paymentIntent($payment, [
            'status' => StripePaymentIntent::STATUS_SUCCEEDED,
            'amount_received' => 2500,
        ]);
        $payment->update(['provider_payment_id' => $paymentIntent->id]);

        $this
            ->postStripeWebhook(
                Event::PAYMENT_INTENT_SUCCEEDED,
                $paymentIntent->toArray(),
                signatureSecret: 'wrong-secret',
            )
            ->assertBadRequest();

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertSame('pending', $payment->booking->refresh()->status);
    }

    public function test_payment_failure_webhook_marks_pending_payment_failed(): void
    {
        $payment = $this->stripePayment();
        $paymentIntent = $this->paymentIntent($payment, [
            'status' => StripePaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
        ]);
        $payment->update(['provider_payment_id' => $paymentIntent->id]);

        $this
            ->postStripeWebhook(Event::PAYMENT_INTENT_PAYMENT_FAILED, $paymentIntent->toArray())
            ->assertNoContent();

        $this->assertSame('failed', $payment->refresh()->status);
        $this->assertSame('pending', $payment->booking->refresh()->status);
    }

    public function test_late_successful_payment_is_queued_for_refund(): void
    {
        Queue::fake();

        $payment = $this->stripePayment();
        $payment->booking->update(['expires_at' => now()->subMinute()]);
        $paymentIntent = $this->paymentIntent($payment, [
            'status' => StripePaymentIntent::STATUS_SUCCEEDED,
            'amount_received' => 2500,
        ]);
        $payment->update(['provider_payment_id' => $paymentIntent->id]);

        $this
            ->postStripeWebhook(Event::PAYMENT_INTENT_SUCCEEDED, $paymentIntent->toArray())
            ->assertNoContent();

        $this->assertSame('refund_pending', $payment->refresh()->status);
        $this->assertSame('cancelled', $payment->booking->refresh()->status);
        Queue::assertPushed(
            ProcessPaymentRefund::class,
            fn (ProcessPaymentRefund $job): bool => $job->paymentId === $payment->id
                && $job->refundAttempt === 1,
        );
    }

    public function test_refund_webhook_recovers_refund_when_api_response_was_lost(): void
    {
        $payment = $this->stripePayment([
            'status' => 'refund_failed',
            'provider_payment_id' => 'pi_refunded',
            'refund_attempts' => 1,
        ]);
        $payment->booking->update(['status' => 'cancelled']);
        $refund = [
            'id' => 're_recovered',
            'object' => 'refund',
            'status' => 'succeeded',
            'amount' => 2500,
            'currency' => 'eur',
            'payment_intent' => 'pi_refunded',
            'metadata' => [
                'booking_id' => (string) $payment->booking_id,
                'payment_id' => (string) $payment->id,
            ],
        ];

        $this
            ->postStripeWebhook(Event::REFUND_UPDATED, $refund)
            ->assertNoContent();

        $this->assertSame('refunded', $payment->refresh()->status);
        $this->assertSame('re_recovered', $payment->provider_refund_id);
    }

    public function test_refund_retries_keep_the_same_stripe_idempotency_key(): void
    {
        $payment = $this->stripePayment([
            'provider_payment_id' => 'pi_refund_retry',
            'refund_attempts' => 1,
            'status' => 'refund_failed',
        ]);
        $stripe = new class extends StripePaymentService
        {
            public function refundKey(Payment $payment): string
            {
                return $this->refundIdempotencyKey($payment);
            }
        };
        $firstKey = $stripe->refundKey($payment);

        $payment->update(['refund_attempts' => 2]);

        $this->assertSame($firstKey, $stripe->refundKey($payment->refresh()));
        $this->assertSame("payment-{$payment->id}-refund", $firstKey);
    }

    private function stripePayment(array $attributes = []): Payment
    {
        $customer = User::factory()->customer()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'total_amount' => 25,
        ]);

        return Payment::factory()->create([
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'provider' => 'stripe',
            'amount' => 25,
            ...$attributes,
        ]);
    }

    private function paymentIntent(Payment $payment, array $attributes = []): StripePaymentIntent
    {
        return StripePaymentIntent::constructFrom([
            'id' => 'pi_'.str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT),
            'object' => 'payment_intent',
            'status' => StripePaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
            'amount' => 2500,
            'amount_received' => 0,
            'currency' => 'eur',
            'client_secret' => 'pi_client_secret',
            'metadata' => [
                'booking_id' => (string) $payment->booking_id,
                'payment_id' => (string) $payment->id,
                'customer_id' => (string) $payment->customer_id,
            ],
            ...$attributes,
        ]);
    }

    private function postStripeWebhook(
        string $eventType,
        array $stripeObject,
        string $signatureSecret = 'stripe-webhook-test-secret',
    ): TestResponse {
        $configuredSecret = 'stripe-webhook-test-secret';
        config()->set('services.stripe.webhook_secret', $configuredSecret);
        $payload = json_encode([
            'id' => 'evt_'.str_replace('.', '_', $eventType),
            'object' => 'event',
            'type' => $eventType,
            'data' => [
                'object' => $stripeObject,
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $signatureSecret);

        return $this->call(
            'POST',
            '/api/v1/stripe/webhooks',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $payload,
        );
    }
}
