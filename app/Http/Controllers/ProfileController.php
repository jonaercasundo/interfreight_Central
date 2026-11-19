<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load('roles');
        $lastLogin = AuditLog::where('actor_id', $user->id)->where('action', 'auth.login_succeeded')->latest('created_at')->value('created_at');
        $lastPasswordChange = AuditLog::where('actor_id', $user->id)->whereIn('action', ['profile.password_changed', 'auth.password_reset'])->latest('created_at')->value('created_at');

        return view('app', [
            'page' => 'profile',
            'profileManagement' => [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'is_active' => $user->is_active,
                    'created_at' => $user->created_at?->toIso8601String(),
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'last_login_at' => $lastLogin ? CarbonImmutable::parse($lastLogin)->toIso8601String() : null,
                    'password_last_changed_at' => $lastPasswordChange ? CarbonImmutable::parse($lastPasswordChange)->toIso8601String() : null,
                ],
                'flash_status' => session('status'),
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request, AuditLogger $audit)
    {
        $data = $request->validated();
        $user = $request->user();
        $user->update($data);
        $audit->record('profile.updated', $user);

        return $request->expectsJson()
            ? response()->json(['status' => 'Profile updated.', 'user' => ['name' => $user->name, 'email' => $user->email]])
            : back()->with('status', 'Profile updated.');
    }

    public function password(ChangePasswordRequest $request, AuditLogger $audit)
    {
        $data = $request->validated();
        $request->user()->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $audit->record('profile.password_changed', $request->user());
        $request->session()->regenerate();
        $request->session()->put(['ifc_password_hash' => $request->user()->password, 'ifc_password_user_id' => $request->user()->id]);

        return $request->expectsJson()
            ? response()->json(['status' => 'Password updated.'])
            : back()->with('status', 'Password updated.');
    }
}
