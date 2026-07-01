<?php

namespace Modules\Payments\Services;

use App\Services\BookingPaymentStateService;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;

class StripeWebhookService
{
    public function __construct(
        private BookingPaymentStateService $bookingPaymentStates,
    ) {}

    public function handle(Event $event): void
    {
        $stripeObject = $event->data->object;

        if (in_array($event->type, [
            Event::PAYMENT_INTENT_SUCCEEDED,
            Event::PAYMENT_INTENT_PAYMENT_FAILED,
            Event::PAYMENT_INTENT_CANCELED,
        ], true) && $stripeObject instanceof PaymentIntent) {
            $this->bookingPaymentStates->synchronizeStripePaymentIntent($stripeObject, $event->type);

            return;
        }

        if (in_array($event->type, [
            Event::REFUND_CREATED,
            Event::REFUND_UPDATED,
            Event::REFUND_FAILED,
        ], true) && $stripeObject instanceof Refund) {
            $this->bookingPaymentStates->synchronizeStripeRefund($stripeObject);
        }
    }
}
