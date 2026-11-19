<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_baseline_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('baseline_version');
            $table->string('currency', 3);
            $table->string('payment_terms', 180)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('billing_contact', 120)->nullable();
            $table->text('billing_address')->nullable();
            $table->text('remarks')->nullable();
            $table->string('workflow_status', 32)->default('draft')->index();
            $table->string('tax_configuration_status', 24)->default('unverified');
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('discount_total', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->nullable();
            $table->decimal('total', 16, 2)->nullable();
            $table->json('issued_snapshot')->nullable();
            $table->string('official_number', 80)->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'workflow_status']);
            $table->index(['shipment_id', 'workflow_status']);
        });

        Schema::create('billing_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('authorization_reference', 80);
            $table->string('description', 220);
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('unit', 32)->nullable();
            $table->decimal('unit_price', 16, 4)->nullable();
            $table->decimal('authorized_amount', 16, 2);
            $table->decimal('previously_invoiced', 16, 2)->default(0);
            $table->decimal('line_amount', 16, 2);
            $table->string('currency', 3);
            $table->string('tax_treatment', 16)->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('tax_amount', 16, 2)->nullable();
            $table->json('source_snapshot');
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('billing_invoice_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->text('comments')->nullable();
            $table->timestamp('acted_at');
            $table->index(['invoice_id', 'acted_at']);
        });

        Schema::create('billing_invoice_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_path', 500)->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['invoice_id', 'created_at']);
        });

        Schema::create('billing_invoice_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 48);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason')->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['invoice_id', 'created_at']);
        });

        Schema::create('billing_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->string('exception_type', 48);
            $table->string('severity', 16)->default('normal');
            $table->foreignId('shipment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->text('explanation');
            $table->string('assigned_department', 24)->default('Billing');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity']);
        });

        Schema::create('billing_accounting_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('billing_invoices')->restrictOnDelete();
            $table->string('event_key', 120)->unique();
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_accounting_outbox');
        Schema::dropIfExists('billing_exceptions');
        Schema::dropIfExists('billing_invoice_events');
        Schema::dropIfExists('billing_invoice_documents');
        Schema::dropIfExists('billing_invoice_approvals');
        Schema::dropIfExists('billing_invoice_lines');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_invoice_sequences');
    }
};
