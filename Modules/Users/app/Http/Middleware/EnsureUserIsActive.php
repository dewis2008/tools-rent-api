<?php

namespace Modules\Users\Http\Middleware;

use App\Enums\ApiErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->status !== 'active' || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'code' => ApiErrorCode::AccountInactive->value,
                'message' => __('Your account is not active.'),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
