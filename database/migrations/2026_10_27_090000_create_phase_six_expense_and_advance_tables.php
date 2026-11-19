<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('label', 100);
            $table->string('estimate_category', 48)->nullable()->index();
            $table->string('budget_category', 48)->nullable()->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_advances', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->string('department', 24)->index();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('budget_allocation_id')->nullable()->constrained('budget_allocations')->restrictOnDelete();
            $table->decimal('requested_amount', 16, 2);
            $table->char('currency', 3);
            $table->text('purpose');
            $table->json('category_codes');
            $table->date('required_by')->nullable()->index();
            $table->string('status', 28)->default('draft')->index();
            $table->decimal('approved_amount', 16, 2)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['shipment_id', 'department', 'status']);
            $table->index(['requester_id', 'status']);
        });

        Schema::create('cash_advance_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::create('actual_expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('transport_trip_id')->nullable()->constrained('shipment_transport_trips')->restrictOnDelete();
            $table->foreignId('forwarding_booking_id')->nullable()->constrained('shipment_forwarding_bookings')->restrictOnDelete();
            $table->foreignId('container_id')->nullable()->constrained('shipment_containers')->restrictOnDelete();
            $table->string('department', 24)->index();
            $table->foreignId('responsible_employee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('description', 220);
            $table->string('vendor', 180)->nullable();
            $table->date('incurred_on')->index();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->string('tax_treatment', 16)->default('none');
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('withholding_amount', 16, 2)->default(0);
            $table->foreignId('cost_estimate_line_id')->nullable()->constrained('cost_estimate_lines')->restrictOnDelete();
            $table->foreignId('budget_allocation_id')->nullable()->constrained('budget_allocations')->restrictOnDelete();
            $table->string('payment_reference', 100)->nullable();
            $table->string('recoverability', 36)->default('undetermined')->index();
            $table->string('client_charge_status', 24)->default('not_applicable')->index();
            $table->string('client_approval_reference', 100)->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->string('receipt_status', 28)->default('missing')->index();
            $table->string('claim_status', 24)->default('not_submitted')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'category_id', 'incurred_on']);
            $table->index(['department', 'status', 'incurred_on']);
            $table->index(['vendor', 'amount', 'incurred_on']);
        });

        Schema::create('expense_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::create('expense_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->constrained('actual_expenses')->restrictOnDelete();
            $table->uuid('document_group');
            $table->string('category', 24)->index();
            $table->string('original_name', 255);
            $table->string('disk_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('version')->default(1);
            $table->string('verification_status', 24)->default('pending')->index();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('upload_key', 120)->nullable()->unique();
            $table->timestamps();
            $table->index(['expense_id', 'document_group', 'version']);
        });

        Schema::create('expense_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->constrained('actual_expenses')->restrictOnDelete();
            $table->string('reference', 28)->unique();
            $table->string('adjustment_type', 16);
            $table->decimal('amount', 16, 2);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('submitted')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['expense_id', 'status']);
        });

        Schema::create('expense_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->constrained('actual_expenses')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['expense_id', 'occurred_at']);
        });

        Schema::create('cash_advance_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_advance_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 24);
            $table->string('source_reference', 100);
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->date('disbursed_on');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 24)->default('pending_verification')->index();
            $table->timestamps();
            $table->unique(['source_type', 'source_reference']);
        });

        Schema::create('cash_advance_liquidations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('cash_advance_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->decimal('submitted_expense_total', 16, 2)->default(0);
            $table->decimal('verified_expense_total', 16, 2)->default(0);
            $table->decimal('reimbursement_due', 16, 2)->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['cash_advance_id', 'status']);
        });

        Schema::create('cash_advance_liquidation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('liquidation_id')->constrained('cash_advance_liquidations')->restrictOnDelete();
            $table->foreignId('expense_id')->constrained('actual_expenses')->restrictOnDelete();
            $table->decimal('eligible_amount', 16, 2);
            $table->decimal('verified_amount', 16, 2)->nullable();
            $table->string('status', 16)->default('submitted')->index();
            $table->timestamps();
            $table->unique(['liquidation_id', 'expense_id']);
        });

        Schema::create('cash_advance_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_advance_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100)->unique();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->date('returned_on');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 24)->default('pending_verification')->index();
            $table->timestamps();
        });

        Schema::create('expense_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->nullable()->constrained('actual_expenses')->restrictOnDelete();
            $table->foreignId('cash_advance_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32)->index();
            $table->string('dedupe_key', 140)->unique();
            $table->string('severity', 16)->default('normal');
            $table->string('status', 20)->default('open')->index();
            $table->text('description');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status']);
        });

        $categories = [
            ['shipping_line', 'Shipping-line charges', 'shipping_line'], ['ocean_air_freight', 'Ocean / air freight', 'ocean_air_freight'],
            ['port_terminal', 'Port and terminal charges', 'port_terminal'], ['customs_processing', 'Customs processing', 'customs_processing'],
            ['duties_taxes', 'Duties and taxes', 'duties_taxes'], ['transport_trucking', 'Transport / trucking', 'trucking_transport'],
            ['fuel', 'Fuel', 'trucking_transport'], ['toll_fees', 'Toll fees', 'trucking_transport'], ['parking', 'Parking', 'trucking_transport'],
            ['driver_allowance', 'Driver allowance', 'trucking_transport'], ['forwarding', 'Forwarding charges', 'forwarding'],
            ['warehousing', 'Warehousing', 'warehousing'], ['handling', 'Handling', 'handling_labor'], ['documentation', 'Documentation', 'documentation'],
            ['insurance', 'Insurance', 'insurance'], ['equipment_container', 'Equipment / container charges', 'other'],
            ['other_operating', 'Other operating costs', 'other'], ['miscellaneous', 'Miscellaneous', 'miscellaneous'],
        ];
        foreach ($categories as [$code, $label, $estimateCategory]) {
            DB::table('expense_categories')->updateOrInsert(['code' => $code], ['label' => $label, 'estimate_category' => $estimateCategory, 'budget_category' => $estimateCategory, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $permissions = [
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.verify', 'expenses.adjust', 'expenses.receipts.upload', 'expenses.receipts.verify',
            'cash-advances.view', 'cash-advances.request', 'cash-advances.review', 'cash-advances.approve', 'cash-advances.disbursement.record', 'cash-advances.disbursement.verify', 'cash-advances.liquidate', 'cash-advances.liquidation.verify', 'cash-advances.returns.verify', 'expenses.client-approval.record',
            'budgets.utilization.view', 'expenses.variance.view', 'expenses.exceptions.view', 'expenses.exceptions.approve',
        ];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $grants = [
            'Operations' => ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.receipts.upload', 'cash-advances.view', 'cash-advances.request', 'cash-advances.liquidate', 'expenses.variance.view', 'expenses.exceptions.view'],
            'Transport' => ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.receipts.upload', 'cash-advances.view', 'cash-advances.request', 'cash-advances.liquidate', 'expenses.variance.view', 'expenses.exceptions.view'],
            'Forwarding' => ['expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.receipts.upload', 'cash-advances.view', 'cash-advances.request', 'cash-advances.liquidate', 'expenses.variance.view', 'expenses.exceptions.view'],
            'Accounting' => ['expenses.view', 'expenses.verify', 'expenses.receipts.verify', 'cash-advances.view', 'cash-advances.review', 'cash-advances.disbursement.record', 'cash-advances.liquidation.verify', 'cash-advances.returns.verify', 'budgets.utilization.view', 'expenses.variance.view', 'expenses.exceptions.view'],
            'Finance' => ['expenses.view', 'expenses.client-approval.record', 'cash-advances.view', 'cash-advances.approve', 'cash-advances.disbursement.verify', 'expenses.exceptions.view', 'expenses.exceptions.approve', 'expenses.variance.view', 'budgets.utilization.view'],
        ];
        foreach ($grants as $roleName => $names) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($names);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_exceptions');
        Schema::dropIfExists('cash_advance_returns');
        Schema::dropIfExists('cash_advance_liquidation_lines');
        Schema::dropIfExists('cash_advance_liquidations');
        Schema::dropIfExists('cash_advance_disbursements');
        Schema::dropIfExists('expense_events');
        Schema::dropIfExists('expense_adjustments');
        Schema::dropIfExists('expense_documents');
        Schema::dropIfExists('expense_sequences');
        Schema::dropIfExists('actual_expenses');
        Schema::dropIfExists('cash_advance_sequences');
        Schema::dropIfExists('cash_advances');
        Schema::dropIfExists('expense_categories');
        // Permission rows and grants remain intact if the schema is rolled back.
    }
};
