<?php

namespace Modules\ToolImages\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToolImagesResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'url' => route('api.toolImages.file', ['toolImage' => $this->resource]),
        ];
    }
}
