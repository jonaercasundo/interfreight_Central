<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AuditEventCatalog;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IdentityAndAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->admin = User::factory()->create(['password' => 'a-strong-test-password']);
        $this->admin->assignRole('Administrator');
    }

    public function test_login_regenerates_session_and_logout_invalidates_it(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $before = session()->getId();
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'a-strong-test-password'])->assertRedirect('/dashboard');
        $this->assertNotSame($before, session()->getId());
        $this->get('/dashboard')->assertOk()->assertSee('InterFreight');
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_succeeded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);
    }

    public function test_executive_account_opens_the_executive_command_center_after_login(): void
    {
        $executive = User::factory()->create(['password' => 'a-strong-test-password']);
        $executive->assignRole('Executive');

        $this->post('/login', ['email' => $executive->email, 'password' => 'a-strong-test-password'])->assertRedirect('/executive');
        $this->get('/executive')->assertOk()->assertSee('data-page="executive"', false);
    }

    public function test_password_submission_requires_https_outside_local_and_test_environments(): void
    {
        config(['app.env' => 'production']);
        $this->postJson('/login', ['email' => $this->admin->email, 'password' => 'a-strong-test-password'])
            ->assertBadRequest()->assertJsonPath('message', 'A secure HTTPS connection is required for password operations.');
        $this->assertGuest();
    }

    public function test_bootstrap_admin_command_refuses_noninteractive_execution(): void
    {
        $this->artisan('ifc:admin', ['email' => 'bootstrap@example.test'])->assertExitCode(1);
        $this->assertDatabaseMissing('users', ['email' => 'bootstrap@example.test']);
    }

    public function test_bootstrap_admin_command_creates_an_interactive_admin_and_audit_event(): void
    {
        $this->admin->update(['is_active' => false]);
        $this->artisan('ifc:admin', ['email' => 'first-admin@example.test'])
            ->expectsQuestion('Administrator name', 'First IFC Admin')
            ->expectsQuestion('Choose a password (minimum 12 characters)', 'temporary-test-password-123')
            ->expectsQuestion('Confirm password', 'temporary-test-password-123')
            ->expectsOutput('Administrator account created. Sign in at /login.')
            ->assertExitCode(0);
        $created = User::where('email', 'first-admin@example.test')->firstOrFail();
        $this->assertTrue($created->hasRole('Administrator'));
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check('temporary-test-password-123', $created->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.bootstrap_administrator_created', 'target_id' => $created->id]);
    }

    public function test_inactive_account_cannot_login_or_use_api(): void
    {
        $user = User::factory()->create(['password' => 'a-strong-test-password', 'is_active' => false]);
        $user->assignRole('Sales');
        $this->post('/login', ['email' => $user->email, 'password' => 'a-strong-test-password'])->assertSessionHasErrors('email');
        $this->actingAs($user)->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_exact_initial_roles_and_least_privilege_permissions_are_seeded(): void
    {
        $this->assertEqualsCanonicalizing(AccessControlSeeder::ROLES, Role::orderBy('name')->pluck('name')->all());
        $sales = Role::findByName('Sales');
        $exec = Role::findByName('Executive');
        $this->assertTrue($sales->hasPermissionTo('department.sales.view'));
        $this->assertFalse($sales->hasPermissionTo('department.finance.view'));
        $this->assertTrue($exec->hasPermissionTo('executive.overview.view'));
        $this->assertFalse($exec->hasPermissionTo('users.create'));
        $this->seed(AccessControlSeeder::class);
        $this->assertSame(count(AccessControlSeeder::ROLES), Role::count());
        $sales->revokePermissionTo('assistant.use');
        $this->seed(AccessControlSeeder::class);
        $this->assertFalse($sales->fresh()->hasPermissionTo('assistant.use'));
    }

    public function test_direct_administration_routes_require_the_matching_permission(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);
        $exec = User::factory()->create();
        $exec->assignRole('Executive');
        $this->actingAs($exec)->get('/admin/users')->assertForbidden();
        $executivePage = $this->actingAs($exec)->get('/executive')->assertOk()->assertSee('data-page="executive"', false);
        $this->assertFalse($executivePage->headers->has('Location'));
        $this->actingAs($exec)->get('/admin/audit')->assertOk();
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales)->get('/sales')->assertOk();
        $this->actingAs($sales)->get('/finance')->assertForbidden();
        $this->actingAs($sales)->get('/executive')->assertForbidden();
    }

    public function test_all_seeded_roles_only_receive_their_authorized_sidebar_destinations(): void
    {
        $departments = [
            'Sales' => '/sales',
            'Operations' => '/operations',
            'Transport' => '/transport',
            'Forwarding' => '/forwarding',
            'Accounting' => '/accounting',
            'Billing' => '/billing',
            'Finance' => '/finance',
        ];
        $routes = ['/dashboard', '/executive', '/sales/dashboard', ...array_values($departments), '/billing', '/admin/users', '/admin/roles', '/admin/audit', '/admin/integrations', '/profile'];

        foreach (AccessControlSeeder::ROLES as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $allowed = match ($role) {
                'Administrator' => array_values(array_filter($routes, fn ($route) => ! in_array($route, ['/accounting', '/executive'], true))),
                'Executive' => ['/dashboard', '/executive', ...array_values($departments), '/billing', '/admin/audit', '/admin/integrations', '/profile'],
                'Sales' => ['/dashboard', '/sales/dashboard', '/sales', '/profile'],
                'Sales Manager' => ['/dashboard', '/sales/dashboard', '/sales', '/profile'],
                'Finance' => ['/dashboard', '/accounting', '/finance', '/billing', '/profile'],
                'Accounting' => ['/dashboard', '/accounting', '/billing', '/profile'],
                'Billing' => ['/dashboard', $departments[$role], '/billing', '/profile'],
                default => ['/dashboard', $departments[$role], '/profile'],
            };

            foreach ($routes as $route) {
                $response = $this->actingAs($user)->get($route);
                $expected = in_array($route, $allowed, true) ? ($role !== 'Administrator' && $route === '/dashboard' ? 302 : 200) : 403;
                $this->assertSame($expected, $response->getStatusCode(), "Role {$role} at {$route}");
            }
        }
    }

    public function test_administrator_pages_render(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);
        $this->actingAs($this->admin)->get('/admin/users')->assertOk()->assertSee('User Management')->assertSee('data-page="users"', false);
        $this->actingAs($this->admin)->get('/admin/roles')->assertOk()->assertSee('Roles & Permissions')->assertSee('data-page="roles"', false)->assertSee('data-role-management', false);
        $this->actingAs($this->admin)->get('/admin/audit')->assertOk()->assertSee('Audit Logs')->assertSee('data-page="audit"', false)->assertSee('data-audit-management', false);
        $integration = $this->actingAs($this->admin)->get('/admin/integrations')->assertOk()->assertSee('data-page="integrations"', false)->assertSee('data-integration-health', false)->assertSee('Integration Health')->assertDontSee('Back to Dashboard');
        $this->assertFalse($integration->headers->has('Location'));
        $this->actingAs($this->admin)->get('/profile')->assertOk()->assertSee('My Profile');
    }

    public function test_profile_uses_shared_shell_and_exposes_only_safe_account_details(): void
    {
        $this->actingAs($this->admin);
        app(AuditLogger::class)->record('auth.login_succeeded', $this->admin);
        app(AuditLogger::class)->record('profile.password_changed', $this->admin);

        $response = $this->actingAs($this->admin)->get('/profile')->assertOk()->assertSee('data-page="profile"', false)->assertSee('data-profile-management', false)->assertSee('My Profile');
        $this->assertStringNotContainsString('a-strong-test-password', $response->getContent());
        preg_match('/data-profile-management="([^"]*)"/', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $payload = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($this->admin->name, $payload['user']['name']);
        $this->assertSame(['Administrator'], $payload['user']['roles']);
        $this->assertNotEmpty($payload['user']['last_login_at']);
        $this->assertNotEmpty($payload['user']['password_last_changed_at']);
        $this->assertArrayNotHasKey('password', $payload['user']);
        $this->assertArrayNotHasKey('remember_token', $payload['user']);

        auth()->logout();
        $this->get('/profile')->assertRedirect('/login');
    }

    public function test_profile_update_validates_name_email_and_never_changes_roles(): void
    {
        $duplicate = User::factory()->create();
        $oldVerification = $this->admin->email_verified_at;
        $this->actingAs($this->admin)->putJson('/profile', [
            'name' => 'Updated IFC Name',
            'email' => 'updated-ifc@example.test',
            'roles' => ['Sales'],
            'is_active' => false,
        ])->assertOk()->assertJsonPath('status', 'Profile updated.')->assertJsonMissingPath('user.roles');

        $this->assertSame('Updated IFC Name', $this->admin->fresh()->name);
        $this->assertSame('updated-ifc@example.test', $this->admin->fresh()->email);
        $this->assertTrue($this->admin->fresh()->hasRole('Administrator'));
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertEquals($oldVerification, $this->admin->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.updated', 'actor_id' => $this->admin->id]);

        $this->putJson('/profile', ['name' => '', 'email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
        $this->putJson('/profile', ['name' => 'Duplicate Test', 'email' => $duplicate->email])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_password_change_requires_current_password_and_confirmation_then_keeps_session(): void
    {
        $originalRememberToken = $this->admin->remember_token;
        $this->actingAs($this->admin)->putJson('/profile/password', [
            'current_password' => 'wrong-current-password',
            'password' => 'a-new-strong-password-123',
            'password_confirmation' => 'a-new-strong-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson('/profile/password', [
            'current_password' => 'a-strong-test-password',
            'password' => 'a-new-strong-password-123',
            'password_confirmation' => 'different-new-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson('/profile/password', [
            'current_password' => 'a-strong-test-password',
            'password' => 'a-new-strong-password-123',
            'password_confirmation' => 'a-new-strong-password-123',
        ])->assertOk()->assertJsonPath('status', 'Password updated.');

        $this->assertTrue(Hash::check('a-new-strong-password-123', $this->admin->fresh()->password));
        $this->assertNotSame($originalRememberToken, $this->admin->fresh()->remember_token);
        $this->assertAuthenticatedAs($this->admin->fresh());
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.password_changed', 'actor_id' => $this->admin->id]);
        $this->getJson('/api/me')->assertOk();
    }

    public function test_integration_health_refresh_is_permission_checked_json_and_does_not_redirect(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok']),
            '*/internal/diagnostic' => Http::response(['status' => 'authenticated']),
        ]);
        config(['services.ai.url' => 'http://127.0.0.1:8001', 'services.ai.token' => 'test-only-secret']);
        $response = $this->actingAs($this->admin)->get('/admin/integrations/status', ['Accept' => 'application/json']);
        $response->assertOk()->assertJsonPath('service.status', 'connected')->assertJsonPath('authentication.status', 'authenticated');
        $this->assertStringNotContainsString('test-only-secret', $response->getContent());
        $this->assertFalse($response->headers->has('Location'));

        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales)->get('/admin/integrations')->assertForbidden();
        $this->actingAs($sales)->getJson('/admin/integrations/status')->assertForbidden();
    }

    public function test_roles_page_loads_seeded_roles_and_registered_permissions_into_the_shared_shell(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/roles')->assertOk()->assertSee('data-page="roles"', false);
        preg_match('/data-role-management="([^"]*)"/', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $payload = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(count(AccessControlSeeder::ROLES), $payload['roles']);
        $this->assertEqualsCanonicalizing(AccessControlSeeder::ROLES, array_column($payload['roles'], 'name'));
        $this->assertCount(Permission::count(), $payload['permissions']);
        $this->assertContains('department.finance.view', array_column($payload['permissions'], 'name'));
    }

    public function test_audit_search_category_actor_and_manila_date_filters_scope_the_table(): void
    {
        $this->createAuditEvent('auth.login_succeeded', $this->admin, User::class, $this->admin->id, '2026-10-22 16:30:00');
        $this->createAuditEvent('auth.login_failed', null, null, null, '2026-10-22 16:35:00');
        $this->createAuditEvent('users.created', $this->admin, User::class, $this->admin->id, '2026-10-22 15:59:59');

        $response = $this->actingAs($this->admin)->get('/admin/audit?category=authentication&actor='.$this->admin->id.'&from=2026-10-23&to=2026-10-23');
        $data = $this->auditManagementFromResponse($response);
        $this->assertSame(1, $data['logs']['total']);
        $this->assertSame('Successful Login', $data['logs']['data'][0]['event']);
        $this->assertSame('2026-10-23T00:30:00+08:00', $data['logs']['data'][0]['occurred_at_manila']);

        $searchResponse = $this->actingAs($this->admin)->get('/admin/audit?search=Failed%20Login&category=security');
        $searchData = $this->auditManagementFromResponse($searchResponse);
        $this->assertSame(1, $searchData['logs']['total']);
        $this->assertSame('Failed Login', $searchData['logs']['data'][0]['event']);
    }

    public function test_audit_date_range_validation_and_timestamp_sort_pagination_preserve_filters(): void
    {
        $this->actingAs($this->admin)->get('/admin/audit?from=2026-10-24&to=2026-10-23')->assertSessionHasErrors('to');

        for ($day = 1; $day <= 27; $day++) {
            $this->createAuditEvent('auth.login_succeeded', $this->admin, User::class, $this->admin->id, sprintf('2026-10-%02d 04:00:00', $day));
        }
        $response = $this->actingAs($this->admin)->get('/admin/audit?category=authentication&direction=asc');
        $data = $this->auditManagementFromResponse($response);
        $this->assertSame('2026-10-01T12:00:00+08:00', $data['logs']['data'][0]['occurred_at_manila']);
        $this->assertSame(2, $data['logs']['last_page']);
        $this->assertStringContainsString('category=authentication', $data['logs']['next_page_url']);
        $this->assertStringContainsString('direction=asc', $data['logs']['next_page_url']);
    }

    public function test_audit_detail_payload_uses_human_labels_and_redacts_sensitive_metadata(): void
    {
        $log = $this->createAuditEvent('auth.login_failed', null, null, null, '2026-10-23 04:30:00', [
            'correlation_id' => 'trace-42',
            'password' => 'do-not-display',
            'nested' => ['authorization' => 'Bearer do-not-display', 'safe_value' => '<b>plain text</b>'],
        ], '192.0.2.44');
        $this->createAuditEvent('unrecognized.event_type', $this->admin, null, null, '2026-10-23 05:30:00');

        $data = $this->auditManagementFromResponse($this->actingAs($this->admin)->get('/admin/audit?category=security'));
        $event = $data['logs']['data'][0];
        $this->assertSame($log->id, $event['id']);
        $this->assertSame('Failed Login', $event['event']);
        $this->assertSame('security', $event['category']);
        $this->assertSame('trace-42', $event['request_id']);
        $this->assertSame('192.0.2.44', $event['source_ip']);
        $this->assertSame('[Redacted]', $event['metadata']['password']);
        $this->assertSame('[Redacted]', $event['metadata']['nested']['authorization']);
        $this->assertSame('<b>plain text</b>', $event['metadata']['nested']['safe_value']);
        $this->assertSame('Unrecognized Event Type', AuditEventCatalog::label('unrecognized.event_type'));
        $this->assertSame('other', AuditEventCatalog::category('unrecognized.event_type'));
    }

    public function test_audit_export_is_authorized_filtered_and_redacts_sensitive_metadata(): void
    {
        $failure = $this->createAuditEvent('auth.login_failed', null, null, null, '2026-10-23 04:30:00', ['token' => 'secret-export-value']);
        $this->createAuditEvent('auth.login_succeeded', $this->admin, User::class, $this->admin->id, '2026-10-23 04:31:00');
        $executive = User::factory()->create();
        $executive->assignRole('Executive');
        $this->actingAs($executive)->get('/admin/audit/export')->assertForbidden();

        $response = $this->actingAs($this->admin)->get('/admin/audit/export?category=security')->assertDownload();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Failed Login', $csv);
        $this->assertStringContainsString('auth.login_failed', $csv);
        $this->assertStringContainsString('[Redacted]', $csv);
        $this->assertStringNotContainsString('secret-export-value', $csv);
        $this->assertStringNotContainsString('auth.login_succeeded', $csv);
        $this->assertSame('auth.login_failed', $failure->fresh()->action);
    }

    private function createAuditEvent(string $action, ?User $actor, ?string $targetType, ?int $targetId, string $createdAt, array $metadata = [], ?string $ip = null): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata,
            'ip_address' => $ip,
            'user_agent' => null,
            'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        ]);
    }

    private function auditManagementFromResponse($response): array
    {
        $response->assertOk();
        preg_match('/data-audit-management="([^"]*)"/', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_admin_can_create_a_user_with_roles_and_audit_event(): void
    {
        Notification::fake();
        $this->actingAs($this->admin)->post('/admin/users', ['name' => 'Avery Tan', 'email' => 'avery@example.test', 'roles' => ['Operations']])->assertRedirect();
        $user = User::where('email', 'avery@example.test')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole('Operations'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.created', 'target_id' => $user->id]);
        $this->actingAs($this->admin)->post('/admin/users/'.$user->id.'/reset-link')->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.password_reset_requested', 'target_id' => $user->id]);
    }

    public function test_temporary_password_creation_is_hashed_gated_and_never_returned_or_audited(): void
    {
        $password = 'TempPass!2468Aa';
        $response = $this->actingAs($this->admin)->postJson('/admin/users', [
            'name' => 'Temporary Access', 'email' => 'temporary@example.test', 'roles' => ['Operations'],
            'setup_method' => 'temporary_password', 'password' => $password, 'password_confirmation' => $password,
        ])->assertOk()->assertJsonMissing(['password' => $password]);
        $this->assertStringNotContainsString($password, $response->getContent());
        $user = User::where('email', 'temporary@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.temporary_password_issued', 'target_id' => $user->id]);
        $this->assertStringNotContainsString($password, json_encode(AuditLog::where('target_id', $user->id)->get()->toArray()));

        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/password/first-login');
        $this->get('/dashboard')->assertRedirect('/password/first-login');
        $this->getJson('/api/me')->assertStatus(423)->assertJsonPath('redirect', url('/password/first-login'));
        $this->get('/password/first-login')->assertOk()->assertSee('Set your new password');
        $this->post('/password/first-login', [
            'current_password' => $password, 'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors('password');
        $newPassword = 'AnotherPass!3579Bb';
        $this->post('/password/first-login', [
            'current_password' => $password, 'password' => $newPassword, 'password_confirmation' => 'MismatchPass!3579Bb',
        ])->assertSessionHasErrors('password');
        $this->post('/password/first-login', [
            'current_password' => $password, 'password' => 'weakpass', 'password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors('password');
        $this->post('/password/first-login', [
            'current_password' => $password, 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertRedirect('/dashboard');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.first_login_password_changed', 'target_id' => $user->id]);
        $this->get('/dashboard')->assertRedirect('/operations');
    }

    public function test_admin_can_set_temporary_password_but_cannot_reset_privileged_user_without_privileged_permission(): void
    {
        $subject = User::factory()->create(['password' => 'OldPass!2468Aa']);
        $oldPasswordHash = $subject->password;
        $subject->assignRole('Sales');
        config(['session.driver' => 'database']);
        foreach (['subject-session-a', 'subject-session-b'] as $sessionId) {
            DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $subject->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        $password = 'ResetPass!2468Aa';
        $this->actingAs($this->admin)->postJson('/admin/users/'.$subject->id.'/temporary-password', [
            'password' => $password, 'password_confirmation' => $password,
        ])->assertOk()->assertJsonMissing(['password' => $password]);
        $subject->refresh();
        $this->assertTrue($subject->must_change_password);
        $this->assertTrue(Hash::check($password, $subject->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $subject->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.temporary_password_issued', 'target_id' => $subject->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.temporary_password_reset', 'target_id' => $subject->id]);
        $this->actingAs($subject)->withSession(['ifc_password_user_id' => $subject->id, 'ifc_password_hash' => $oldPasswordHash])->get('/profile')->assertRedirect('/login');
        $this->actingAs($this->admin);
        $this->postJson('/admin/users/'.$this->admin->id.'/temporary-password', ['password' => $password, 'password_confirmation' => $password])->assertForbidden();

        $limited = User::factory()->create();
        Role::findByName('Sales')->givePermissionTo('users.password.reset');
        $limited->assignRole('Sales');
        $this->actingAs($limited)->postJson('/admin/users/'.$this->admin->id.'/temporary-password', [
            'password' => $password, 'password_confirmation' => $password,
        ])->assertForbidden();
    }

    public function test_last_administrator_cannot_be_deactivated_or_demoted(): void
    {
        $this->actingAs($this->admin)->put('/admin/users/'.$this->admin->id, ['name' => $this->admin->name, 'email' => $this->admin->email, 'roles' => ['Executive'], 'is_active' => 1])->assertSessionHasErrors('roles');
        $this->assertTrue($this->admin->fresh()->hasRole('Administrator'));
    }

    public function test_non_admin_cannot_escalate_roles_or_read_audit_history(): void
    {
        $support = Role::findByName('Sales');
        $support->givePermissionTo('users.update');
        $user = User::factory()->create();
        $user->assignRole('Sales');
        $this->actingAs($user)->put('/admin/users/'.$user->id, ['name' => $user->name, 'email' => $user->email, 'roles' => ['Administrator'], 'is_active' => 1])->assertForbidden();
        $this->actingAs($user)->get('/admin/audit')->assertForbidden();
        $this->assertFalse($user->fresh()->hasRole('Administrator'));
    }

    public function test_user_update_without_role_or_status_permission_preserves_both_assignments(): void
    {
        Role::findByName('Sales')->givePermissionTo('users.update');
        $actor = User::factory()->create();
        $actor->assignRole('Sales');
        $subject = User::factory()->create(['is_active' => true]);
        $subject->assignRole('Operations');

        $this->actingAs($actor)->putJson('/admin/users/'.$subject->id, [
            'name' => 'Updated Name',
            'email' => $subject->email,
        ])->assertOk()->assertJsonPath('user.name', 'Updated Name');

        $this->assertTrue($subject->fresh()->is_active);
        $this->assertTrue($subject->fresh()->hasRole('Operations'));
    }

    public function test_administrator_cannot_place_privileged_permissions_on_a_department_role(): void
    {
        $role = Role::findByName('Sales');
        $this->actingAs($this->admin)->put('/admin/roles/'.$role->id, ['permissions' => ['dashboard.view', 'users.create']])->assertSessionHasErrors('permissions');
        $this->assertFalse($role->fresh()->hasPermissionTo('users.create'));
    }

    public function test_role_permissions_change_is_audited_with_before_and_after_values(): void
    {
        $role = Role::findByName('Sales');
        $before = $role->permissions()->orderBy('name')->pluck('name')->all();
        $after = [...$before, 'audit.view'];

        $this->actingAs($this->admin)->putJson('/admin/roles/'.$role->id, ['permissions' => $after])
            ->assertOk()->assertJsonPath('message', 'Permissions saved.')
            ->assertJsonPath('permissions', collect($after)->sort()->values()->all());

        $this->assertTrue($role->fresh()->hasPermissionTo('audit.view'));
        $log = AuditLog::where('action', 'roles.permissions_changed')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame($role->id, $log->target_id);
        $this->assertSame($before, $log->metadata['before']);
        $this->assertSame(collect($after)->sort()->values()->all(), $log->metadata['after']);
        $this->assertNotNull($log->created_at);
    }

    public function test_role_permission_update_rejects_missing_unknown_and_duplicate_slugs(): void
    {
        $role = Role::findByName('Sales');
        $url = '/admin/roles/'.$role->id;

        $this->actingAs($this->admin)->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->putJson($url, ['permissions' => ['dashboard.view', 'not.registered']])->assertUnprocessable()->assertJsonValidationErrors('permissions.1');
        $this->putJson($url, ['permissions' => ['dashboard.view', 'dashboard.view']])->assertUnprocessable()->assertJsonValidationErrors('permissions.1');
        $this->assertSame([
            'additional-charges.client-request.prepare', 'additional-charges.client-response.record', 'additional-charges.history.view',
            'additional-charges.internal-review', 'additional-charges.view', 'assistant.use', 'billing.invoices.view', 'clients.create', 'clients.update',
            'clients.view', 'commercial-changes.authorize', 'commercial-changes.view', 'cost-estimates.create', 'cost-estimates.history.view',
            'cost-estimates.submit', 'cost-estimates.update', 'cost-estimates.view', 'dashboard.view', 'department.sales.view',
            'forecasts.create', 'forecasts.update', 'forecasts.view', 'forecasts.withdraw', 'inquiries.cancel', 'inquiries.create',
            'inquiries.review', 'inquiries.submit', 'inquiries.update', 'inquiries.view', 'margins.view', 'profile.update', 'profile.view',
            'quotations.client-response.record', 'quotations.create', 'quotations.export', 'quotations.history.view', 'quotations.issue',
            'quotations.revise', 'quotations.submit', 'quotations.update', 'quotations.view', 'sales.availability.view', 'sales.calendar.view', 'sales.dashboard.view',
            'shipments.create', 'shipments.timeline.view', 'shipments.view',
        ], $role->fresh()->permissions()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'roles.permissions_changed']);
    }

    public function test_role_permission_updates_require_permissions_manage_even_if_roles_manage_is_granted(): void
    {
        $role = Role::findByName('Sales');
        $role->givePermissionTo('roles.manage');
        $actor = User::factory()->create();
        $actor->assignRole('Sales');

        $this->actingAs($actor)->putJson('/admin/roles/'.$role->id, ['permissions' => ['dashboard.view']])->assertForbidden();
        $this->assertFalse($role->fresh()->hasPermissionTo('dashboard.view') && $role->fresh()->permissions()->count() === 1);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'roles.permissions_changed']);
    }

    public function test_administrator_role_cannot_lose_any_registered_permission_and_unchanged_save_is_not_audited(): void
    {
        $role = Role::findByName('Administrator');
        $all = Permission::orderBy('name')->pluck('name')->all();

        $this->actingAs($this->admin)->putJson('/admin/roles/'.$role->id, ['permissions' => array_slice($all, 1)])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->assertEqualsCanonicalizing($all, $role->fresh()->permissions()->pluck('name')->all());

        $this->putJson('/admin/roles/'.$role->id, ['permissions' => $all])
            ->assertOk()->assertJsonPath('permissions', $all);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'roles.permissions_changed']);
    }

    public function test_audit_records_are_read_only_and_passwords_are_not_logged(): void
    {
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertRedirect();
        $failure = AuditLog::where('action', 'auth.login_failed')->firstOrFail();
        $this->assertStringNotContainsString('password', json_encode($failure->metadata));
        $this->actingAs($this->admin)->put('/admin/audit', [])->assertStatus(405);
    }

    public function test_password_reset_uses_a_one_time_token_and_stores_only_a_hash(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);
        $user->assignRole('Sales');
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
        $notification = Notification::sent($user, ResetPassword::class)->first();
        $this->post('/reset-password', ['token' => $notification->token, 'email' => $user->email, 'password' => 'New-Strong-Password-123!', 'password_confirmation' => 'New-Strong-Password-123!'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('New-Strong-Password-123!', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'target_id' => $user->id]);
    }
}
