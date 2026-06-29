<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Users\Http\Requests\SendEmailVerificationNotificationRequest;

class EmailVerificationNotificationController extends Controller
{
    public function store(SendEmailVerificationNotificationRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->validated('email'))
            ->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => __('If the account exists and is unverified, a verification email has been sent.'),
        ]);
    }
}
