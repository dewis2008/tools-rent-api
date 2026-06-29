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
            'image' => ['sometimes', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_path' => ['prohibited'],
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

        $toolId = $this->input('tool_id');

        if (! is_scalar($toolId) || filter_var($toolId, FILTER_VALIDATE_INT) === false) {
            return true;
        }

        $tool = Tool::query()->find((int) $toolId);

        return ! $tool || $user->vendorProfile()->whereKey($tool->vendor_id)->exists();
    }
}
