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
        if (! Schema::hasTable('ai_configurations')) {
            throw new RuntimeException('The AI configuration base migration must run before extending its controls.');
        }

        Schema::table('ai_configurations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('timeout_seconds')->default(20);
            $table->unsignedSmallInteger('max_output_tokens')->default(500);
        });

        foreach (['admin.ai-config.view', 'admin.ai-config.manage', 'admin.ai-config.test'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $administrator = Role::query()->where('name', 'Administrator')->where('guard_name', 'web')->first();
        if ($administrator) {
            $administrator->givePermissionTo(Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', ['admin.ai-config.view', 'admin.ai-config.manage', 'admin.ai-config.test'])
                ->get());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin.ai-config.view', 'admin.ai-config.manage', 'admin.ai-config.test'])
            ->get();

        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->users()->detach();
            $permission->delete();
        }

        if (Schema::hasTable('ai_configurations')) {
            Schema::table('ai_configurations', function (Blueprint $table): void {
                $table->dropColumn(['timeout_seconds', 'max_output_tokens']);
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
