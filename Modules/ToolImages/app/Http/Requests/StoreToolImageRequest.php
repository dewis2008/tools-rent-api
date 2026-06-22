<?php

namespace Modules\ToolImages\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreToolImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tool_id' => ['required', 'integer', 'exists:tools,id'],
            'image_path' => ['required', 'string', 'max:255'],
            'is_main' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
