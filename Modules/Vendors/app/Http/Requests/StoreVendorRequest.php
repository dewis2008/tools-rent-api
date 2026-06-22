<?php

namespace Modules\Vendors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Vendors\Models\VendorProfile;

class StoreVendorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:vendor_profiles,user_id'],
            'business_name' => ['required', 'string', 'max:255'],
            'company_code' => ['nullable', 'string', 'max:50'],
            'vat_code' => ['nullable', 'string', 'max:50'],
            'verification_status' => ['sometimes', 'required', 'in:pending,approved,rejected'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('create', VendorProfile::class)) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return (int) $this->input('user_id') === $user->id
            && ! $this->hasAny(['verification_status', 'rating']);
    }
}
