<?php

namespace Modules\Vendors\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Vendors\Models\VendorProfile;

class StoreVendorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('vendor_profiles', 'user_id')->whereNull('deleted_at'),
            ],
            'business_name' => ['required', 'string', 'max:255'],
            'company_code' => ['nullable', 'string', 'max:50'],
            'vat_code' => ['nullable', 'string', 'max:50'],
            'verification_status' => ['prohibited'],
            'rating' => ['prohibited'],
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

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('user_id')) {
                    return;
                }

                $user = User::query()->find($this->input('user_id'));

                if ($user?->role === 'vendor') {
                    return;
                }

                $validator->errors()->add('user_id', __('The profile must belong to a vendor.'));
            },
        ];
    }
}
