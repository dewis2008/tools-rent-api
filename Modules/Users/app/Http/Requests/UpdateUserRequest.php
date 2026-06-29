<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['sometimes', 'required', 'in:admin,vendor,customer'],
            'status' => ['sometimes', 'required', 'in:active,blocked,pending'],
        ];
    }

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('update', $this->route('user'))) {
            return false;
        }

        return $user->role === 'admin' || ! $this->hasAny(['role', 'status']);
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAny(['role', 'status'])) {
                    return;
                }

                $user = $this->route('user');
                $status = $this->input('status', $user->status);
                $role = $this->input('role', $user->role);

                if ($status !== 'active') {
                    return;
                }

                if ($role === 'vendor') {
                    $errorField = $this->has('status') ? 'status' : 'role';

                    $validator->errors()->add(
                        $errorField,
                        __('Vendors must be activated by approving their vendor profile.'),
                    );

                    return;
                }

                if ($this->has('status') && ! $user->hasVerifiedEmail()) {
                    $validator->errors()->add('status', __('The email address must be verified before activation.'));
                }
            },
        ];
    }
}
