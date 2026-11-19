<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('budgets.close', 'web');
        $accounting = Role::query()->where('name', 'Accounting')->where('guard_name', 'web')->first();
        $accounting?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Role assignments and permission rows are preserved on rollback.
    }
};
