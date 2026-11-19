<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['collections.view', 'collections.record', 'collections.reconcile', 'collections.history.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $grants = [
            'Accounting' => ['collections.view', 'collections.record', 'collections.history.view'],
            'Finance' => ['collections.view', 'collections.reconcile', 'collections.history.view'],
            'Billing' => ['collections.view', 'collections.history.view'],
            'Executive' => ['collections.view'],
        ];

        foreach ($grants as $roleName => $permissions) {
            $role = Role::query()->where(['name' => $roleName, 'guard_name' => 'web'])->first();
            if ($role) {
                $role->givePermissionTo(Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->get());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep role grants and permission records intact to protect existing access data.
    }
};
