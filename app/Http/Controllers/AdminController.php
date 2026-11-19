<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetTemporaryPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AiServiceClient;
use App\Services\AuditLogger;
use App\Support\AuditEventCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class AdminController extends Controller
{
    public function users(Request $request)
    {
        Gate::authorize('viewAny', User::class);
        $departments = ['Sales', 'Operations', 'Transport', 'Forwarding', 'Accounting', 'Billing', 'Finance'];
        $query = User::with('roles')->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(in_array($request->department, $departments, true), fn ($q) => $q->role($request->department))
            ->when($request->role, fn ($q, $role) => $q->role($role))
            ->orderBy('name');
        $users = $query->paginate(15)->withQueryString();
        $userCount = User::count();
        $activeCount = User::where('is_active', true)->count();
        $roleAssignmentCount = DB::table('model_has_roles')->where('model_type', User::class)->count();

        return view('app', [
            'page' => 'users',
            'userManagement' => [
                'users' => $users->through(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'roles' => $user->getRoleNames()->values(),
                    'is_privileged' => $user->hasRole('Administrator'),
                ]),
                'roles' => Role::orderBy('name')->pluck('name'),
                'departments' => $departments,
                'metrics' => [
                    'total' => $userCount,
                    'active' => $activeCount,
                    'inactive' => $userCount - $activeCount,
                    'assigned_roles' => $roleAssignmentCount,
                ],
                'permissions' => [
                    'create' => $request->user()->can('create', User::class),
                    'update' => $request->user()->can('users.update'),
                    'assign_roles' => $request->user()->can('users.assign-role'),
                    'deactivate' => $request->user()->can('users.deactivate'),
                    'password_reset' => $request->user()->can('users.password.reset'),
                    'privileged_password_reset' => $request->user()->can('users.password.reset.privileged'),
                ],
                'current_user_id' => $request->user()->id,
                'filters' => $request->only(['search', 'status', 'department', 'role']),
            ],
        ]);
    }

    public function createUser(StoreUserRequest $request, AuditLogger $audit)
    {
        Gate::authorize('create', User::class);
        $data = $request->validated();
        $temporaryPassword = ($data['setup_method'] ?? 'setup_link') === 'temporary_password';
        if ($temporaryPassword) {
            abort_unless($request->user()->can('users.password.reset'), 403);
        }
        $user = DB::transaction(function () use ($data, $temporaryPassword, $audit): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $temporaryPassword ? $data['password'] : Str::random(64),
                'is_active' => true,
                'must_change_password' => $temporaryPassword,
            ]);
            $user->syncRoles($data['roles'] ?? []);
            $audit->record('users.created', $user, ['roles' => $data['roles'] ?? []]);
            if ($temporaryPassword) {
                $audit->record('users.temporary_password_issued', $user);
            }

            return $user;
        });
        if ($temporaryPassword) {
            $status = 'Account created with a temporary password. Share it securely; it will not be shown again.';
        } else {
            try {
                Password::sendResetLink(['email' => $user->email]);
                $status = 'Account created. A password setup link was requested.';
            } catch (Throwable $e) {
                Log::warning('IFC initial password email could not be delivered.', ['exception' => get_class($e), 'user_id' => $user->id]);
                $status = 'Account created, but email delivery is unavailable. Configure mail and send a password setup link.';
            }
        }

        return $request->expectsJson()
            ? response()->json(['message' => $status, 'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'roles' => $user->getRoleNames()]])
            : back()->with('status', $status);
    }

    public function updateUser(UpdateUserRequest $request, User $user, AuditLogger $audit)
    {
        Gate::authorize('update', $user);
        $data = $request->validated();
        $wasActive = $user->is_active;
        $roles = $user->roles->pluck('name')->all();
        $newRoles = array_key_exists('roles', $data) ? $data['roles'] : $roles;
        if ($roles !== $newRoles) {
            Gate::authorize('assignRoles', $user);
        }
        if ($request->has('is_active') && $user->is_active !== $request->boolean('is_active')) {
            Gate::authorize('deactivate', $user);
        }
        if ($user->is_active && $user->hasRole('Administrator') && ! in_array('Administrator', $newRoles, true) && User::role('Administrator')->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['roles' => 'The last active Administrator must retain the Administrator role.']);
        }
        if ($request->has('is_active') && ! $request->boolean('is_active') && $user->is_active && ($user->hasRole('Administrator') && User::role('Administrator')->where('is_active', true)->count() <= 1)) {
            return back()->withErrors(['is_active' => 'The last active Administrator cannot be deactivated.']);
        }
        $user->update(['name' => $data['name'], 'email' => $data['email'], 'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $user->is_active]);
        if (array_key_exists('roles', $data)) {
            $user->syncRoles($newRoles);
        }
        $audit->record($wasActive && ! $user->is_active ? 'users.deactivated' : (! $wasActive && $user->is_active ? 'users.reactivated' : 'users.updated'), $user, ['roles_changed' => $roles !== $newRoles]);
        if ($roles !== $newRoles) {
            $audit->record('users.roles_changed', $user, ['roles' => $newRoles]);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'User updated.', 'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'is_active' => $user->is_active, 'roles' => $user->getRoleNames()]])
            : back()->with('status', 'User updated.');
    }

    public function resetLink(Request $request, User $user, AuditLogger $audit)
    {
        abort_unless($request->user()->can('users.update'), 403);
        try {
            Password::sendResetLink(['email' => $user->email]);
            $audit->record('users.password_reset_requested', $user);

            return $request->expectsJson()
                ? response()->json(['message' => 'A password reset link was requested for this account.'])
                : back()->with('status', 'A password reset link was requested for this account.');
        } catch (Throwable $e) {
            Log::warning('IFC administrator password reset email could not be delivered.', ['exception' => get_class($e), 'user_id' => $user->id]);

            return $request->expectsJson()
                ? response()->json(['message' => 'Email delivery is unavailable. Configure mail and try again.'], 503)
                : back()->withErrors(['email' => 'Email delivery is unavailable. Configure mail and try again.']);
        }
    }

    public function setTemporaryPassword(SetTemporaryPasswordRequest $request, User $user, AuditLogger $audit)
    {
        Gate::authorize('resetPassword', $user);
        $data = $request->validated();
        $sessionId = $request->session()->getId();
        DB::transaction(function () use ($user, $data, $sessionId, $audit): void {
            $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => true, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database' && Schema::hasTable(config('session.table'))) {
                DB::table(config('session.table'))->where('user_id', $user->id)->where('id', '!=', $sessionId)->delete();
            }
            $audit->record('users.temporary_password_issued', $user);
            $audit->record('users.temporary_password_reset', $user);
        });

        return response()->json(['message' => 'Temporary password set. Share it securely; it will not be shown again. The user must change it at next sign-in.']);
    }

    public function audit(Request $request)
    {
        $filters = $this->validatedAuditFilters($request);
        $direction = $filters['direction'] ?? 'desc';
        $logs = $this->auditQuery($filters)->orderBy('created_at', $direction)->orderBy('id', $direction)
            ->paginate(25)->withQueryString()->through(fn (AuditLog $log) => $this->presentAuditLog($log));
        $loginEvents = AuditLog::query()->whereIn('action', ['auth.login_succeeded', 'auth.login_failed'])->count();
        $changes = AuditLog::query()->where(fn ($query) => $query->where('action', 'like', 'users.%')->orWhere('action', 'like', 'roles.%')->orWhere('action', 'like', 'permissions.%'))->count();
        $securityFailures = AuditLog::query()->where(fn ($query) => $query->where('action', 'like', 'security.%')->orWhere('action', '=', 'auth.login_failed'))->count();
        $actors = User::query()->whereIn('id', AuditLog::query()->select('actor_id')->whereNotNull('actor_id')->distinct())
            ->orderBy('name')->limit(250)->get(['id', 'name', 'email']);
        $sessionErrors = session('errors');

        return view('app', [
            'page' => 'audit',
            'auditManagement' => [
                'logs' => $logs,
                'actors' => $actors,
                'categories' => AuditEventCatalog::CATEGORIES,
                'filters' => array_merge(['search' => '', 'category' => '', 'actor' => '', 'from' => '', 'to' => '', 'direction' => 'desc'], $filters),
                'summary' => [
                    'total' => AuditLog::count(),
                    'login_events' => $loginEvents,
                    'changes' => $changes,
                    'security_failures' => $securityFailures,
                    'scope' => 'All recorded events. Filters apply to the table below.',
                ],
                'errors' => $sessionErrors?->getBag('default')->getMessages() ?? [],
                'canExport' => $request->user()->can('audit.export'),
            ],
        ]);
    }

    public function roles()
    {
        $permissions = Permission::query()->orderBy('name')->get(['id', 'name']);
        $roles = Role::query()->with(['permissions:id,name'])->orderByRaw("CASE WHEN name = 'Administrator' THEN 0 ELSE 1 END")->orderBy('name')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->map(fn (Permission $permission) => ['id' => $permission->id, 'name' => $permission->name])->values(),
        ])->values();

        return view('app', [
            'page' => 'roles',
            'roleManagement' => [
                'roles' => $roles,
                'permissions' => $permissions->map(fn (Permission $permission) => ['id' => $permission->id, 'name' => $permission->name])->values(),
            ],
        ]);
    }

    public function updateRole(Request $request, Role $role, AuditLogger $audit)
    {
        abort_unless($request->user()->hasRole('Administrator') && $request->user()->can('permissions.manage'), 403);
        $data = $request->validate(['permissions' => ['required', 'array', 'max:500'], 'permissions.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'name')]]);
        $permissions = array_values($data['permissions']);
        $restricted = ['users.view', 'users.create', 'users.update', 'users.password.reset', 'users.password.reset.privileged', 'users.deactivate', 'users.assign-role', 'roles.manage', 'permissions.manage', 'audit.export', 'shipments.manage'];
        if ($role->name !== 'Administrator' && array_intersect($restricted, $permissions)) {
            $message = 'Administrative permissions can only be assigned to the Administrator role.';

            return $request->expectsJson() ? response()->json(['message' => $message, 'errors' => ['permissions' => [$message]]], 422) : back()->withErrors(['permissions' => $message]);
        }
        $before = $role->permissions()->orderBy('name')->pluck('name')->all();
        $after = Permission::query()->whereIn('name', $permissions)->orderBy('name')->pluck('name')->all();
        if ($role->name === 'Administrator' && $after !== Permission::query()->orderBy('name')->pluck('name')->all()) {
            $message = 'The Administrator role must retain every registered permission.';

            return $request->expectsJson() ? response()->json(['message' => $message, 'errors' => ['permissions' => [$message]]], 422) : back()->withErrors(['permissions' => $message]);
        }
        if ($before !== $after) {
            DB::transaction(function () use ($role, $permissions, $audit, $before, $after): void {
                $role->syncPermissions($permissions);
                $audit->record('roles.permissions_changed', $role, ['before' => $before, 'after' => $after]);
            });
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Permissions saved.', 'permissions' => $after]);
        }

        return back()->with('status', 'Role permissions updated.');
    }

    public function exportAudit(Request $request)
    {
        abort_unless($request->user()->can('audit.export'), 403);
        $filters = $this->validatedAuditFilters($request);
        $rows = $this->auditQuery($filters)->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(10000)->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['timestamp_manila', 'actor_name', 'actor_email', 'event', 'action_identifier', 'category', 'target_type', 'target_id', 'source_ip', 'request_id', 'details']);
            foreach ($rows as $row) {
                $values = [
                    $row->created_at?->copy()->timezone('Asia/Manila')->toIso8601String(),
                    $row->actor?->name,
                    $row->actor?->email,
                    AuditEventCatalog::label($row->action),
                    $row->action,
                    AuditEventCatalog::CATEGORIES[AuditEventCatalog::category($row->action)],
                    class_basename($row->target_type ?? ''),
                    $row->target_id,
                    $row->ip_address,
                    $this->correlationId($row->metadata ?? []),
                    json_encode($this->safeAuditMetadata($row->metadata ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
                $values = array_map(static function ($value) {
                    $value = (string) $value;

                    return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
                }, $values);
                fputcsv($out, $values);
            }
            fclose($out);
        }, 'ifc-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function validatedAuditFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::in(array_keys(AuditEventCatalog::CATEGORIES))],
            'actor' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    private function auditQuery(array $filters)
    {
        $query = AuditLog::query()->with('actor:id,name,email');
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->where('action', 'like', "%$search%")
                    ->orWhere('target_type', 'like', "%$search%")
                    ->orWhereHas('actor', fn ($actor) => $actor->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%"));
                $matchingEvents = AuditEventCatalog::matchingLabels($search);
                if ($matchingEvents) {
                    $query->orWhereIn('action', $matchingEvents);
                }
                if (ctype_digit($search)) {
                    $query->orWhere('target_id', (int) $search);
                }
            });
        }
        if (! empty($filters['category'])) {
            AuditEventCatalog::applyCategory($query, $filters['category']);
        }
        if (! empty($filters['actor'])) {
            $query->where('actor_id', $filters['actor']);
        }
        if (! empty($filters['from'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], 'Asia/Manila')->startOfDay()->utc();
            $query->where('created_at', '>=', $from);
        }
        if (! empty($filters['to'])) {
            $toExclusive = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], 'Asia/Manila')->addDay()->startOfDay()->utc();
            $query->where('created_at', '<', $toExclusive);
        }

        return $query;
    }

    private function presentAuditLog(AuditLog $log): array
    {
        $metadata = $this->safeAuditMetadata($log->metadata ?? []);
        if (! request()->user()->can('cost-estimates.view')) {
            unset($metadata['financial_details']);
        }
        $time = $log->created_at?->copy()->utc();

        return [
            'id' => $log->id,
            'occurred_at_utc' => $time?->toIso8601String(),
            'occurred_at_manila' => $time?->copy()->timezone('Asia/Manila')->toIso8601String(),
            'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'email' => $log->actor->email] : null,
            'event' => AuditEventCatalog::label($log->action),
            'action' => $log->action,
            'category' => AuditEventCatalog::category($log->action),
            'category_label' => AuditEventCatalog::CATEGORIES[AuditEventCatalog::category($log->action)],
            'target' => $log->target_type ? ['type' => class_basename($log->target_type), 'id' => $log->target_id] : null,
            'source_ip' => $log->ip_address,
            'request_id' => $this->correlationId($metadata),
            'metadata' => $metadata,
        ];
    }

    private function safeAuditMetadata(array $metadata): array
    {
        $safe = [];
        foreach (array_slice($metadata, 0, 50, true) as $key => $value) {
            if (preg_match('/password|token|secret|authorization|api.?key|credential|cookie|session/i', (string) $key)) {
                $safe[$key] = '[Redacted]';

                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->safeAuditMetadata($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }

        return $safe;
    }

    private function correlationId(array $metadata): ?string
    {
        foreach (['correlation_id', 'request_id', 'trace_id'] as $key) {
            if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                return mb_substr((string) $metadata[$key], 0, 120);
            }
        }

        return null;
    }

    public function integrations(AiServiceClient $service)
    {
        return view('app', ['page' => 'integrations', 'integrationHealth' => $service->healthSnapshot()]);
    }

    public function integrationStatus(AiServiceClient $service)
    {
        return response()->json($service->healthSnapshot());
    }
}
