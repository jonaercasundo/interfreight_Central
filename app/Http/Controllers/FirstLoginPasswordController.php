<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class FirstLoginPasswordController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->must_change_password, 404);

        return view('auth.first-login-password');
    }

    public function update(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->must_change_password, 404);
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);
        if (Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from your temporary password.']);
        }

        $user = $request->user();
        $sessionId = $request->session()->getId();
        DB::transaction(function () use ($user, $data, $sessionId, $audit): void {
            $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database' && Schema::hasTable(config('session.table'))) {
                DB::table(config('session.table'))->where('user_id', $user->id)->where('id', '!=', $sessionId)->delete();
            }
            $audit->record('users.first_login_password_changed', $user);
        });
        $request->session()->regenerate();
        $request->session()->put(['ifc_password_hash' => $user->fresh()->password, 'ifc_password_user_id' => $user->id]);

        return redirect('/dashboard')->with('status', 'Your password has been updated.');
    }
}
