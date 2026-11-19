<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }
        $permissions = [
            'clients.view', 'clients.create', 'clients.update',
            'inquiries.view', 'inquiries.create', 'inquiries.update', 'inquiries.submit', 'inquiries.review', 'inquiries.cancel',
            'forecasts.view', 'forecasts.create', 'forecasts.update', 'forecasts.withdraw',
            'forecasts.accounting.view',
        ];
        foreach ($permissions as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['created_at' => now(), 'updated_at' => now()]);
        }

        $salesRole = DB::table('roles')->where('name', 'Sales')->where('guard_name', 'web')->first();
        if ($salesRole) {
            foreach (array_slice($permissions, 0, 13) as $name) {
                $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
                DB::table('role_has_permissions')->updateOrInsert(['role_id' => $salesRole->id, 'permission_id' => $permissionId]);
            }
        }
        $accountingRole = DB::table('roles')->where('name', 'Accounting')->where('guard_name', 'web')->first();
        if ($accountingRole) {
            $permissionId = DB::table('permissions')->where('name', 'forecasts.accounting.view')->where('guard_name', 'web')->value('id');
            DB::table('role_has_permissions')->updateOrInsert(['role_id' => $accountingRole->id, 'permission_id' => $permissionId]);
        }
        $adminRole = DB::table('roles')->where('name', 'Administrator')->where('guard_name', 'web')->first();
        if ($adminRole) {
            foreach ($permissions as $name) {
                $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
                DB::table('role_has_permissions')->updateOrInsert(['role_id' => $adminRole->id, 'permission_id' => $permissionId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permission records and assignments are retained to avoid removing live access grants.
    }
};
