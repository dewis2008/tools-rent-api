<?php

namespace Modules\ToolImages\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Tools\Models\Tool;

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
        $user = $this->user();

        if (! $user?->can('update', $this->route('toolImage'))) {
            return false;
        }

        if ($user->role === 'admin' || ! $this->has('tool_id')) {
            return true;
        }

        $tool = Tool::query()->find($this->input('tool_id'));

        return $tool && $user->vendorProfile()->whereKey($tool->vendor_id)->exists();
    }
}
