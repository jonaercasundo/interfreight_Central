<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::where('name', 'Executive')->where('guard_name', 'web')->first();
        if ($role) {
            $role->givePermissionTo(['operations.jobs.view', 'transport.jobs.view', 'forwarding.jobs.view']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
