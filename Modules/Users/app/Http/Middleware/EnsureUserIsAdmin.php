<?php

namespace Modules\Users\Http\Middleware;

use App\Enums\ApiErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json([
                'code' => ApiErrorCode::Forbidden->value,
                'message' => __('This action is unauthorized.'),
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
