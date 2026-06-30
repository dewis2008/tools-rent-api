<?php

namespace Modules\LockCodes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\LockCodes\Models\LockCode;

class LockCodeRevealsController extends Controller
{
    public function store(LockCode $lockCode): JsonResponse
    {
        $this->authorize('reveal', $lockCode);

        $user = request()->user();

        Log::info('Lock code revealed.', [
            'lock_code_id' => $lockCode->id,
            'booking_id' => $lockCode->booking_id,
            'user_id' => $user?->id,
            'user_role' => $user?->role,
        ]);

        return response()
            ->json(['code' => $lockCode->code])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
