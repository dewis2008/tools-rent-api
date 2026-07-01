<?php

return [
    'name' => 'ToolImages',
    'disk' => env('TOOL_IMAGE_DISK', 'local'),
    'max_per_tool' => max((int) env('TOOL_IMAGE_MAX_PER_TOOL', 10), 1),
    'max_per_vendor' => max((int) env('TOOL_IMAGE_MAX_PER_VENDOR', 100), 1),
    'uploads_per_minute' => max((int) env('TOOL_IMAGE_UPLOADS_PER_MINUTE', 10), 1),
];
