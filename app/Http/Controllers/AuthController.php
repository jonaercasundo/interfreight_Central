<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizedLandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        return view('auth.login');
    }

    public function authenticate(LoginRequest $request, AuditLogger $audit, AuthorizedLandingPage $landingPage)
    {
        $credentials = $request->validated();
        $user = User::where('email', $credentials['email'])->first();
        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            $audit->record('auth.login_failed', $user);
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }
        $request->session()->regenerate();
        $audit->record('auth.login_succeeded', Auth::user());

        if (Auth::user()->must_change_password) {
            $request->session()->forget('url.intended');

            return redirect()->route('password.first-login');
        }

        // Do not send authenticated users back to an intended URL they may not
        // be authorized to open. The shared resolver applies the same role
        // priority and permission checks as /dashboard.
        return redirect()->to($landingPage->resolve(Auth::user()) ?? '/dashboard');
    }

    public function logout(Request $request, AuditLogger $audit)
    {
        $audit->record('auth.logout', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function forgotPassword(Request $request)
    {
        return view('auth.forgot-password', ['status' => null]);
    }

    public function sendResetLink(Request $request, AuditLogger $audit)
    {
        $request->validate(['email' => ['required', 'email']]);
        $audit->record('auth.password_reset_requested');
        try {
            Password::sendResetLink($request->only('email'));

            return back()->with('status', 'If an account uses that email, password reset instructions will be sent.');
        } catch (Throwable $e) {
            Log::warning('IFC password reset email could not be delivered.', ['exception' => get_class($e)]);

            return back()->with('status', 'Password reset email is unavailable right now. Contact an IFC Administrator.');
        }
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request, AuditLogger $audit)
    {
        $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()]]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) use ($audit): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
            $audit->record('auth.password_reset', $user);
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('status', __($status)) : back()->withErrors(['email' => __($status)]);
    }
}
