<?php

namespace Modules\Vendors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVendorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('vendor_profiles', 'user_id')->ignore($this->route('vendor')),
            ],
            'business_name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_code' => ['nullable', 'string', 'max:50'],
            'vat_code' => ['nullable', 'string', 'max:50'],
            'verification_status' => ['sometimes', 'required', 'in:pending,approved,rejected'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
