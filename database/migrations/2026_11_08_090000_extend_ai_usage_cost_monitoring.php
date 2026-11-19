<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->unique();
            $table->string('correlation_id', 100)->nullable()->index();
            $table->string('request_type', 40)->default('assistant')->index();
            $table->string('module', 60)->default('assistant')->index();
            $table->string('department', 40)->nullable()->index();
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 20, 12)->nullable();
            $table->decimal('estimated_cost_php', 20, 8)->nullable();
            $table->decimal('usd_php_rate', 20, 10)->nullable();
            $table->string('cost_status', 32)->default('pending_pricing');
        });

        Schema::create('ai_model_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('openai');
            $table->string('model', 100);
            $table->decimal('input_usd_per_million', 20, 10);
            $table->decimal('cached_input_usd_per_million', 20, 10)->nullable();
            $table->decimal('output_usd_per_million', 20, 10);
            $table->string('source_url', 500);
            $table->timestamp('verified_at');
            $table->timestamp('effective_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['provider', 'model', 'effective_at']);
        });

        Schema::create('ai_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency', 3)->default('USD');
            $table->char('target_currency', 3)->default('PHP');
            $table->decimal('rate', 20, 10);
            $table->string('source', 120);
            $table->string('source_url', 500)->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamp('verified_at');
            $table->timestamp('effective_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['base_currency', 'target_currency', 'effective_at']);
        });

        Schema::create('ai_monthly_budgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('budget_php', 18, 2);
            $table->unsignedTinyInteger('warning_percent')->default(50);
            $table->unsignedTinyInteger('critical_percent')->default(80);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        Schema::create('ai_budget_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('threshold_percent');
            $table->decimal('spend_php', 18, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['year', 'month', 'threshold_percent']);
        });

        if (Schema::hasTable('permissions') && Schema::hasTable('roles') && Schema::hasTable('role_has_permissions')) {
            $names = ['admin.ai-usage.view', 'admin.ai-pricing.manage', 'admin.ai-fx.manage', 'admin.ai-budget.manage', 'admin.ai-usage.export'];
            foreach ($names as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
            $adminId = DB::table('roles')->where('name', 'Administrator')->where('guard_name', 'web')->value('id');
            if ($adminId) {
                $ids = DB::table('permissions')->whereIn('name', $names)->where('guard_name', 'web')->pluck('id');
                foreach ($ids as $id) {
                    DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $adminId, 'permission_id' => $id]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_budget_alerts');
        Schema::dropIfExists('ai_monthly_budgets');
        Schema::dropIfExists('ai_exchange_rates');
        Schema::dropIfExists('ai_model_prices');
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->dropUnique(['request_id']);
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['request_type']);
            $table->dropIndex(['department']);
            $table->dropIndex(['module']);
            $table->dropColumn(['request_id', 'correlation_id', 'request_type', 'module', 'department', 'cached_input_tokens', 'total_tokens', 'estimated_cost_usd', 'estimated_cost_php', 'usd_php_rate', 'cost_status']);
        });
        if (Schema::hasTable('permissions')) {
            $names = ['admin.ai-usage.view', 'admin.ai-pricing.manage', 'admin.ai-fx.manage', 'admin.ai-budget.manage', 'admin.ai-usage.export'];
            $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
            if (Schema::hasTable('role_has_permissions')) {
                DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            }
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
