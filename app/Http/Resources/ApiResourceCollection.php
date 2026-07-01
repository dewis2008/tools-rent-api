<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

class ApiResourceCollection extends AnonymousResourceCollection
{
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            ...$default,
            ...Arr::except($paginated, ['data', 'links']),
        ];
    }
}
