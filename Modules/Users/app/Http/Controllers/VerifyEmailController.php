<?php

namespace Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailController extends Controller
{
    public function __invoke(int $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        abort_unless(
            hash_equals($hash, sha1($user->getEmailForVerification())),
            Response::HTTP_FORBIDDEN,
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        if ($user->role === 'customer' && $user->status === 'pending') {
            $user->update(['status' => 'active']);
        }

        return response()->json([
            'message' => __('Email address verified.'),
            'requires_vendor_approval' => $user->role === 'vendor' && $user->status !== 'active',
            'user' => $user->refresh(),
        ]);
    }
}
