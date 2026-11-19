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
        Schema::create('shipment_completion_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->string('dimension', 32);
            $table->string('status', 24)->default('not_started');
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['shipment_id', 'dimension']);
            $table->index(['dimension', 'status']);
        });

        Schema::create('financial_closing_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('open');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['shipment_id', 'version']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('financial_closing_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('closing_request_id')->constrained('financial_closing_requests')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->text('comments')->nullable();
            $table->timestamp('acted_at');
            $table->index(['closing_request_id', 'acted_at']);
        });

        Schema::create('financial_closing_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('closing_request_id')->constrained('financial_closing_requests')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->char('currency', 3)->nullable();
            $table->json('snapshot');
            $table->char('sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['shipment_id', 'version']);
        });

        $permissions = [
            'shipments.completion.view', 'shipments.completion.operations.verify',
            'shipments.completion.billing.verify', 'shipments.completion.collections.verify',
            'shipments.reconciliation.view', 'shipments.reconciliation.manage',
            'profitability.dashboard.view', 'profitability.shipments.view', 'profitability.reports.view', 'profitability.cost-details.view',
            'financial-closing.view', 'financial-closing.prepare', 'financial-closing.submit',
            'financial-closing.review', 'financial-closing.approve', 'financial-closing.close',
            'financial-closing.reopen', 'financial-closing.snapshots.view',
            'financial-closing.history.view', 'financial-closing.reports.export',
        ];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $grants = [
            'Operations' => ['shipments.completion.view', 'shipments.completion.operations.verify', 'shipments.reconciliation.view'],
            'Transport' => ['shipments.completion.view', 'shipments.reconciliation.view'],
            'Forwarding' => ['shipments.completion.view', 'shipments.reconciliation.view'],
            'Billing' => ['shipments.completion.view', 'shipments.completion.billing.verify', 'shipments.reconciliation.view'],
            'Accounting' => ['shipments.completion.view', 'shipments.completion.collections.verify', 'shipments.reconciliation.view', 'shipments.reconciliation.manage', 'financial-closing.view', 'financial-closing.review', 'financial-closing.snapshots.view', 'financial-closing.history.view'],
            'Finance' => ['shipments.completion.view', 'shipments.reconciliation.view', 'shipments.reconciliation.manage', 'profitability.dashboard.view', 'profitability.shipments.view', 'profitability.reports.view', 'profitability.cost-details.view', 'financial-closing.view', 'financial-closing.prepare', 'financial-closing.submit', 'financial-closing.review', 'financial-closing.close', 'financial-closing.reopen', 'financial-closing.snapshots.view', 'financial-closing.history.view', 'financial-closing.reports.export'],
            'Executive' => ['shipments.completion.view', 'shipments.reconciliation.view', 'profitability.dashboard.view', 'profitability.reports.view', 'financial-closing.view', 'financial-closing.approve', 'financial-closing.snapshots.view', 'financial-closing.history.view'],
        ];
        foreach ($grants as $roleName => $names) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(Permission::where('guard_name', 'web')->whereIn('name', $names)->get());
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_closing_snapshots');
        Schema::dropIfExists('financial_closing_reviews');
        Schema::dropIfExists('financial_closing_requests');
        Schema::dropIfExists('shipment_completion_milestones');
        foreach ([
            'shipments.completion.view', 'shipments.completion.operations.verify', 'shipments.completion.billing.verify',
            'shipments.completion.collections.verify', 'shipments.reconciliation.view', 'shipments.reconciliation.manage',
            'profitability.dashboard.view', 'profitability.shipments.view', 'profitability.reports.view', 'profitability.cost-details.view',
            'financial-closing.view', 'financial-closing.prepare', 'financial-closing.submit', 'financial-closing.review',
            'financial-closing.approve', 'financial-closing.close', 'financial-closing.reopen',
            'financial-closing.snapshots.view', 'financial-closing.history.view', 'financial-closing.reports.export',
        ] as $name) {
            Permission::where('name', $name)->where('guard_name', 'web')->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
