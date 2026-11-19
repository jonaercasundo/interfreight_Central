<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('openai');
            $table->string('model', 100)->default('gpt-5.4-mini');
            $table->boolean('enabled')->default(false);
            $table->boolean('tutorials_enabled')->default(true);
            $table->text('api_key_ciphertext')->nullable();
            $table->string('last_test_status', 40)->default('not_tested');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_successful_test_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32)->default('openai');
            $table->string('model', 100)->nullable();
            $table->string('status', 24);
            $table->string('error_category', 40)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        if (Schema::hasTable('permissions') && Schema::hasTable('roles') && Schema::hasTable('role_has_permissions')) {
            foreach (['ai.configuration.view', 'ai.configuration.manage', 'ai.configuration.test'] as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
            $administratorId = DB::table('roles')->where('name', 'Administrator')->where('guard_name', 'web')->value('id');
            if ($administratorId) {
                $permissionIds = DB::table('permissions')->whereIn('name', ['ai.configuration.view', 'ai.configuration.manage', 'ai.configuration.test'])->where('guard_name', 'web')->pluck('id');
                foreach ($permissionIds as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $administratorId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_configurations');
        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('name', ['ai.configuration.view', 'ai.configuration.manage', 'ai.configuration.test'])->where('guard_name', 'web')->pluck('id');
            if (Schema::hasTable('role_has_permissions')) {
                DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            }
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
