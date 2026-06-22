<?php

namespace Modules\Tools\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreToolRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendor_profiles,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price_per_day' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:pending,active,inactive,rejected'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
