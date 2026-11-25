<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePasswordChange
{
    private const ALLOWED = ['api/user', 'api/change-password', 'api/logout'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->must_change_password && !in_array($request->path(), self::ALLOWED, true)) {
            return response()->json([
                'message' => 'Choose your own password first (Change password), then continue.',
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}
