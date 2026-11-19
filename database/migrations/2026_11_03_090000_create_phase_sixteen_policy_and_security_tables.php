<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('status', 24)->default('pending_approval')->index();
            $table->json('policy');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_reference', 160)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'effective_from']);
        });

        Schema::create('financial_policy_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_version_id')->constrained('financial_policy_versions')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->string('approval_reference', 160)->nullable();
            $table->char('policy_sha256', 64);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['policy_version_id', 'created_at']);
        });

        Schema::create('billing_issuance_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('status', 24)->default('pending_approval')->index();
            $table->json('legal_entity');
            $table->json('tax_rules');
            $table->json('numbering_rules');
            $table->json('document_rules');
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finance_approval_reference', 160)->nullable();
            $table->string('legal_approval_reference', 160)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'effective_from']);
        });

        Schema::create('billing_invoice_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->char('sha256', 64);
            $table->string('reason', 180)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['invoice_id', 'revision']);
        });

        foreach (['shipment_documents', 'expense_documents', 'additional_charge_evidence', 'billing_invoice_documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('scan_status', 24)->default('not_scanned')->index();
                $table->text('scan_result')->nullable();
                $table->timestamp('scanned_at')->nullable();
            });
        }

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        foreach (['financial-policies.view', 'financial-policies.manage', 'financial-policies.approve', 'billing.statutory.view', 'billing.statutory.manage', 'billing.statutory.approve'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        foreach (['billing_invoice_documents', 'additional_charge_evidence', 'expense_documents', 'shipment_documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['scan_status']);
                $table->dropColumn(['scan_status', 'scan_result', 'scanned_at']);
            });
        }
        Schema::dropIfExists('billing_invoice_revisions');
        Schema::dropIfExists('billing_issuance_profiles');
        Schema::dropIfExists('financial_policy_events');
        Schema::dropIfExists('financial_policy_versions');
        Permission::whereIn('name', ['financial-policies.view', 'financial-policies.manage', 'financial-policies.approve', 'billing.statutory.view', 'billing.statutory.manage', 'billing.statutory.approve'])->where('guard_name', 'web')->delete();
    }
};
