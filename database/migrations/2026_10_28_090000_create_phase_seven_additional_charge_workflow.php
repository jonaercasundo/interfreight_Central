<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_charge_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('department', 24)->index();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commercial_baseline_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('baseline_version')->nullable();
            $table->string('category', 48)->index();
            $table->string('description', 220);
            $table->text('justification');
            $table->decimal('actual_cost', 16, 2);
            $table->decimal('proposed_amount', 16, 2);
            $table->decimal('approved_amount', 16, 2)->nullable();
            $table->char('currency', 3);
            $table->string('tax_treatment', 16)->default('none');
            $table->string('internal_status', 28)->default('draft')->index();
            $table->string('client_status', 28)->default('not_requested')->index();
            $table->string('classification', 36)->default('under_investigation')->index();
            $table->text('classification_reason')->nullable();
            $table->foreignId('classified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('client_requested_at')->nullable();
            $table->timestamp('client_responded_at')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->string('client_representative', 180)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['shipment_id', 'client_status', 'created_at'], 'charge_req_shipment_status_idx');
            $table->index(['internal_status', 'client_status', 'created_at'], 'charge_req_review_status_idx');
        });

        Schema::create('additional_charge_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::create('additional_charge_expense_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('additional_charge_requests')->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained('actual_expenses')->restrictOnDelete();
            $table->decimal('allocated_cost', 16, 2);
            $table->timestamps();
            $table->unique(['request_id', 'expense_id']);
            $table->index('expense_id');
        });

        Schema::create('additional_charge_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('additional_charge_requests')->restrictOnDelete();
            $table->string('document_type', 32)->index();
            $table->string('original_name', 255);
            $table->string('disk_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('verification_status', 24)->default('pending')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('additional_charge_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('additional_charge_requests')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 48)->index();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['request_id', 'created_at']);
        });

        Schema::create('commercial_change_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('additional_charge_requests')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('commercial_baseline_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('baseline_version')->nullable();
            $table->unsignedInteger('authorization_version')->default(1);
            $table->decimal('approved_amount', 16, 2);
            $table->char('currency', 3);
            $table->string('client_representative', 180)->nullable();
            $table->foreignId('evidence_id')->constrained('additional_charge_evidence')->restrictOnDelete();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('effective_at');
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['shipment_id', 'effective_at']);
        });

        $permissions = [
            'additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit',
            'additional-charges.internal-review', 'additional-charges.client-request.prepare', 'additional-charges.client-response.record',
            'additional-charges.client-response.verify', 'additional-charges.history.view', 'commercial-changes.view',
            'commercial-changes.authorize', 'commercial-changes.billing-eligibility.view', 'expense-recoverability.view',
            'expense-recoverability.classify', 'profit-impact.view',
        ];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_change_authorizations');
        Schema::dropIfExists('additional_charge_events');
        Schema::dropIfExists('additional_charge_evidence');
        Schema::dropIfExists('additional_charge_expense_links');
        Schema::dropIfExists('additional_charge_requests');
        Schema::dropIfExists('additional_charge_sequences');
        foreach ([
            'additional-charges.view', 'additional-charges.create', 'additional-charges.update', 'additional-charges.submit',
            'additional-charges.internal-review', 'additional-charges.client-request.prepare', 'additional-charges.client-response.record',
            'additional-charges.client-response.verify', 'additional-charges.history.view', 'commercial-changes.view',
            'commercial-changes.authorize', 'commercial-changes.billing-eligibility.view', 'expense-recoverability.view',
            'expense-recoverability.classify', 'profit-impact.view',
        ] as $name) {
            Permission::where(['name' => $name, 'guard_name' => 'web'])->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
