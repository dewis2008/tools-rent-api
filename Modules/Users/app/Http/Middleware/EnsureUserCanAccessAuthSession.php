<?php

namespace Modules\Users\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessAuthSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $isPendingVendor = $user?->role === 'vendor' && $user->status === 'pending';

        if (! $user || ! $user->hasVerifiedEmail()) {
            return $this->forbiddenResponse();
        }

        if ($user->status !== 'active' && ! $isPendingVendor) {
            return $this->forbiddenResponse();
        }

        return $next($request);
    }

    private function forbiddenResponse(): Response
    {
        return response()->json([
            'message' => __('Your account is not active.'),
        ], Response::HTTP_FORBIDDEN);
    }
}
