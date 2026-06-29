<?php

namespace Modules\Vendors\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $user = $this->user();

        if (! $user?->can('update', $this->route('vendor'))) {
            return false;
        }

        return $user->role === 'admin' || ! $this->hasAny(['user_id', 'verification_status', 'rating']);
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('verification_status')) {
                    return;
                }

                $user = $this->route('vendor')?->user;

                if (! $user || $user->role !== 'vendor') {
                    $validator->errors()->add('verification_status', __('The profile must belong to a vendor.'));

                    return;
                }

                if ($this->input('verification_status') === 'approved' && ! $user->hasVerifiedEmail()) {
                    $validator->errors()->add(
                        'verification_status',
                        __('The vendor must verify their email address before approval.'),
                    );
                }
            },
        ];
    }
}
