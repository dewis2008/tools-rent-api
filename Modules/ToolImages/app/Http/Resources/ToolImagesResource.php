<?php

namespace Modules\ToolImages\Http\Resources;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;
use Modules\Tools\Http\Resources\ToolsResource;

class ToolImagesResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tool_id' => $this->tool_id,
            'is_main' => $this->is_main,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'url' => route('api.toolImages.file', ['toolImage' => $this->resource]),
            'tool' => new ToolsResource($this->whenLoaded('tool')),
        ];
    }
}
