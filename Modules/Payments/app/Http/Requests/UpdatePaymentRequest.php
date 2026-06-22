<?php

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:bookings,id',
                Rule::unique('payments', 'booking_id')->ignore($this->route('payment')),
            ],
            'customer_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'provider' => ['sometimes', 'required', 'in:demo,stripe,paysera,manual'],
            'provider_payment_id' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:pending,paid,failed,refunded'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'required', 'string', 'max:10'],
            'paid_at' => ['nullable', 'date'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
