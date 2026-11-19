<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'cost-estimates.view', 'cost-estimates.create', 'cost-estimates.update', 'cost-estimates.submit', 'cost-estimates.review', 'cost-estimates.history.view', 'cost-estimates.cash.view',
            'quotations.view', 'quotations.create', 'quotations.update', 'quotations.submit', 'quotations.review', 'quotations.approve', 'quotations.issue', 'quotations.client-response.record', 'quotations.revise', 'quotations.history.view', 'quotations.export', 'margins.view', 'margins.policy.manage',
        ];
        foreach ($permissions as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['created_at' => now(), 'updated_at' => now()]);
        }

        $grants = [
            'Executive' => ['quotations.view', 'quotations.approve', 'quotations.history.view', 'margins.view'],
            'Sales' => ['cost-estimates.view', 'cost-estimates.create', 'cost-estimates.update', 'cost-estimates.submit', 'cost-estimates.history.view', 'quotations.view', 'quotations.create', 'quotations.update', 'quotations.submit', 'quotations.issue', 'quotations.client-response.record', 'quotations.revise', 'quotations.history.view', 'quotations.export', 'margins.view'],
            'Accounting' => ['cost-estimates.view', 'cost-estimates.review', 'cost-estimates.cash.view', 'quotations.view', 'quotations.review'],
            'Finance' => ['cost-estimates.view', 'quotations.view', 'quotations.review', 'quotations.approve', 'quotations.history.view', 'margins.view'],
        ];
        foreach ($grants as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->pluck('id');
            foreach ($ids as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $adminId = DB::table('roles')->where('name', 'Administrator')->where('guard_name', 'web')->value('id');
        if ($adminId) {
            foreach (DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('id') as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $adminId, 'permission_id' => $permissionId]);
            }
        }

        $financeId = DB::table('roles')->where('name', 'Finance')->where('guard_name', 'web')->value('id');
        if ($financeId) {
            foreach (DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['margins.policy.manage'])->pluck('id') as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $financeId, 'permission_id' => $permissionId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep additive permission grants to avoid removing access used after deployment.
    }
};
