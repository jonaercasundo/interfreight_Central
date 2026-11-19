<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 100)->unique();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('amount', 16, 2);
            $table->timestamp('received_at');
            $table->string('payment_method', 24);
            $table->string('evidence_reference', 180);
            $table->string('status', 24)->default('pending_reconciliation')->index();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('reconciliation_note')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'currency', 'received_at']);
        });

        Schema::create('collection_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('collection_payment_receipts')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->decimal('amount', 16, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['receipt_id', 'invoice_id']);
            $table->index(['invoice_id', 'receipt_id']);
        });

        Schema::create('collection_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('collection_payment_receipts')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['receipt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_payment_events');
        Schema::dropIfExists('collection_payment_allocations');
        Schema::dropIfExists('collection_payment_receipts');
    }
};
