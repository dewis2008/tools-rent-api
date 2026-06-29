<?php

namespace Modules\Users\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', 'in:admin,vendor,customer'],
            'status' => ['sometimes', 'required', 'in:active,blocked,pending'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('role') !== 'vendor' || $this->input('status') !== 'active') {
                    return;
                }

                $validator->errors()->add(
                    'status',
                    __('Vendors must be activated by approving their vendor profile.'),
                );
            },
        ];
    }
}
