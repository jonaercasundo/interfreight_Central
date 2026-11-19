<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('must_change_password')->default(false)->index();
            });
        }

        foreach (['users.password.reset', 'users.password.reset.privileged'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $administrator = Role::query()->where('name', 'Administrator')->where('guard_name', 'web')->first();
        if ($administrator) {
            $administrator->givePermissionTo(['users.password.reset', 'users.password.reset.privileged']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['must_change_password']);
                $table->dropColumn('must_change_password');
            });
        }
        // Keep permission rows and assignments to avoid deleting authorization data.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
