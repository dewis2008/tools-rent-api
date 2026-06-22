<?php

namespace Modules\LockCodes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLockCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'booking_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:bookings,id',
                Rule::unique('lock_codes', 'booking_id')->ignore($this->route('lockCode')),
            ],
            'code' => ['sometimes', 'required', 'string', 'max:20'],
            'valid_from' => ['sometimes', 'required', 'date'],
            'valid_until' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'in:generated,sent,active,expired,revoked'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
