<?php

namespace Modules\Users\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveOptionalSanctumUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if ($user && ($user->status !== 'active' || ! $user->hasVerifiedEmail())) {
            $user = null;
        }

        $previousUserResolver = Auth::userResolver();

        Auth::resolveUsersUsing(fn () => $user);
        $request->setUserResolver(fn () => $user);

        try {
            return $next($request);
        } finally {
            Auth::resolveUsersUsing($previousUserResolver);
        }
    }
}
