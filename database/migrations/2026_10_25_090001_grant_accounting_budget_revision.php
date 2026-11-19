<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('budgets.revise', 'web');
        $accounting = Role::query()->where('name', 'Accounting')->where('guard_name', 'web')->first();
        $accounting?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep role grants on rollback to avoid removing access from populated environments.
    }
};
