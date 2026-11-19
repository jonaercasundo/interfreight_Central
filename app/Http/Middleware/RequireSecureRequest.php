<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecureRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(config('app.env'), ['local', 'testing'], true) && ! $request->isSecure()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'A secure HTTPS connection is required for password operations.'], 400)
                : response('A secure HTTPS connection is required for password operations.', 400);
        }

        return $next($request);
    }
}
