<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_payment_id' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:pending,paid,failed,refunded'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'paid_at' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('payment')) ?? false;
    }
}
