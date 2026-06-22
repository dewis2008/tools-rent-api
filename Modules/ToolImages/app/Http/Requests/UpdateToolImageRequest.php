<?php

namespace Modules\ToolImages\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateToolImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tool_id' => ['sometimes', 'required', 'integer', 'exists:tools,id'],
            'image_path' => ['sometimes', 'required', 'string', 'max:255'],
            'is_main' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
