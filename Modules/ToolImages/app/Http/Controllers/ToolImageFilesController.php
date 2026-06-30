<?php

namespace Modules\ToolImages\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\ToolImages\Models\ToolImage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ToolImageFilesController extends Controller
{
    public function show(ToolImage $toolImage): StreamedResponse
    {
        $this->authorize('view', $toolImage);

        $disk = Storage::disk((string) config('toolimages.disk', 'local'));

        abort_unless($disk->exists($toolImage->image_path), 404);

        return $disk->response(
            $toolImage->image_path,
            headers: [
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
            ],
        );
    }
}
