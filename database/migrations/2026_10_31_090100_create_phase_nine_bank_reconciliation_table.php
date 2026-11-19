<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('statement_key', 180)->unique();
            $table->string('bank_account_reference', 120);
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->string('transaction_reference', 180);
            $table->decimal('credit_amount', 16, 2);
            $table->char('currency', 3);
            $table->string('counterparty', 180)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('unmatched')->index();
            $table->foreignId('receipt_id')->nullable()->constrained('collection_payment_receipts')->restrictOnDelete();
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();
            $table->index(['transaction_reference', 'currency', 'credit_amount'], 'collection_bank_match_idx');
            $table->index(['transaction_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_bank_transactions');
    }
};
