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
        Schema::create('monthly_budget_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->date('budget_month');
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('department', 40)->default('Accounting');
            $table->char('currency', 3);
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_budget_plan_id')->nullable()->constrained('monthly_budget_plans')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('draft')->index();
            $table->text('explanation')->nullable();
            $table->decimal('requested_total', 16, 2)->default(0);
            $table->decimal('approved_total', 16, 2)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['budget_month', 'department', 'currency', 'version'], 'budget_scope_version_unique');
            $table->index(['budget_month', 'status']);
        });

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_plan_id')->constrained('monthly_budget_plans')->cascadeOnDelete();
            $table->string('category', 48);
            $table->string('description', 180)->nullable();
            $table->decimal('requested_amount', 16, 2)->default(0);
            $table->decimal('approved_amount', 16, 2)->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->timestamps();
            $table->unique(['budget_plan_id', 'category']);
        });

        Schema::create('budget_approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_plan_id')->constrained('monthly_budget_plans')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->text('remarks')->nullable();
            $table->timestamp('acted_at');
            $table->index(['budget_plan_id', 'acted_at']);
        });

        Schema::create('budget_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('budget_plan_id')->constrained('monthly_budget_plans')->restrictOnDelete();
            $table->foreignId('budget_line_id')->constrained('budget_lines')->restrictOnDelete();
            $table->string('requirement_source', 80)->nullable();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->date('allocated_on');
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->text('purpose');
            $table->timestamps();
            $table->index(['budget_plan_id', 'budget_line_id']);
        });

        Schema::create('budget_audit_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_plan_id')->constrained('monthly_budget_plans')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['budget_plan_id', 'created_at']);
        });

        $permissions = [
            'accounting.dashboard.view', 'funding-requirements.view', 'funding-requirements.recalculate',
            'budgets.view', 'budgets.create', 'budgets.update', 'budgets.submit', 'budgets.review',
            'budgets.approve', 'budgets.allocate', 'budgets.revise', 'budgets.close', 'budgets.history.view',
            'cash-forecasts.view', 'commitments.view', 'commitments.manage', 'budget-actuals.view',
            'budget-variances.view',
        ];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Existing roles only; never add permissions to roles during a migration if they don't exist.
        $grants = [
            'Accounting' => ['accounting.dashboard.view', 'funding-requirements.view', 'funding-requirements.recalculate', 'budgets.view', 'budgets.create', 'budgets.update', 'budgets.submit', 'budgets.allocate', 'budgets.revise', 'budgets.close', 'budgets.history.view', 'cash-forecasts.view', 'commitments.view', 'budget-actuals.view', 'budget-variances.view'],
            'Finance' => ['accounting.dashboard.view', 'funding-requirements.view', 'budgets.view', 'budgets.review', 'budgets.approve', 'budgets.revise', 'budgets.history.view', 'cash-forecasts.view', 'commitments.view', 'budget-actuals.view', 'budget-variances.view'],
            'Executive' => ['accounting.dashboard.view', 'funding-requirements.view', 'budgets.view', 'budgets.history.view', 'budget-variances.view'],
        ];
        foreach ($grants as $roleName => $names) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(Permission::query()->where('guard_name', 'web')->whereIn('name', $names)->get());
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_audit_history');
        Schema::dropIfExists('budget_allocations');
        Schema::dropIfExists('budget_approval_steps');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('monthly_budget_plans');
        // Permission rows and grants are intentionally preserved by rollback.
    }
};
