<?php

namespace Modules\LockCodes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLockCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'integer', 'exists:bookings,id', 'unique:lock_codes,booking_id'],
            'code' => ['required', 'string', 'max:20'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after:valid_from'],
            'status' => ['sometimes', 'required', 'in:generated,sent,active,expired,revoked'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
