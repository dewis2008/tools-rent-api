<?php

namespace Modules\Users\Http\Requests;

use App\Http\Requests\FilteredListRequest;
use App\Models\User;

class IndexUserRequest extends FilteredListRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    protected function filterRules(): array
    {
        return [
            'query' => ['sometimes', 'nullable', 'string', 'max:200'],
            'role' => ['sometimes', 'string', 'in:admin,vendor,customer'],
            'status' => ['sometimes', 'string', 'in:active,blocked,pending'],
            'email_verified' => ['sometimes', 'boolean'],
        ];
    }

    protected function sortableColumns(): array
    {
        return [
            'created_at' => 'created_at',
            'name' => 'name',
            'email' => 'email',
            'role' => 'role',
            'status' => 'status',
        ];
    }
}
