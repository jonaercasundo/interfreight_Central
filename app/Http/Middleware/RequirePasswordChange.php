<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->must_change_password || $request->is('password/first-login') || $request->is('logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Change your temporary password to continue.', 'redirect' => route('password.first-login')], 423);
        }

        return redirect()->route('password.first-login');
    }
}
