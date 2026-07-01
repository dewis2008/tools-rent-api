<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Modules\Users\Http\Requests\SendPasswordResetLinkRequest;

class PasswordResetLinksController extends Controller
{
    public function store(SendPasswordResetLinkRequest $request): JsonResponse
    {
        Password::sendResetLink([
            'email' => $request->validated('email'),
        ]);

        return response()->json([
            'message' => __('If an account exists for that email address, a password reset link has been sent.'),
        ]);
    }
}
