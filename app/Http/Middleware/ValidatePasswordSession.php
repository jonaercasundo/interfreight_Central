<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ValidatePasswordSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->hasSession()) {
            $sessionUserId = $request->session()->get('ifc_password_user_id');
            $sessionHash = $request->session()->get('ifc_password_hash');
            if ((int) $sessionUserId === (int) $user->id && $sessionHash && ! hash_equals($user->password, $sessionHash)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() ? response()->json(['message' => 'Your session expired after a password change.'], 401) : redirect('/login')->with('status', 'Please sign in again after your password changed.');
            }
            $request->session()->put(['ifc_password_user_id' => $user->id, 'ifc_password_hash' => $user->password]);
        }

        return $next($request);
    }
}
