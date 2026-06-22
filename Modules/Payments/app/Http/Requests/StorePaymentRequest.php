<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'integer', 'exists:bookings,id', 'unique:payments,booking_id'],
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'provider' => ['sometimes', 'required', 'in:demo,stripe,paysera,manual'],
            'provider_payment_id' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:pending,paid,failed,refunded'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'required', 'string', 'max:10'],
            'paid_at' => ['nullable', 'date'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
