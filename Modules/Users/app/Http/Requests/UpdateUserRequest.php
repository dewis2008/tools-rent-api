<?php

namespace Modules\Users\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
}
