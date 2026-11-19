<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 24);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_value')->default(0);
            $table->unique(['document_type', 'year']);
        });

        Schema::create('cost_estimates', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('inquiry_id')->constrained('client_inquiries')->restrictOnDelete();
            $table->foreignId('forecast_id')->nullable()->constrained('shipment_forecasts')->nullOnDelete();
            $table->foreignId('sales_owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_estimate_id')->nullable()->constrained('cost_estimates')->restrictOnDelete();
            $table->text('revision_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->string('shipment_classification', 16);
            $table->string('transport_mode', 16);
            $table->string('origin', 180);
            $table->string('destination', 180);
            $table->date('planned_window')->nullable();
            $table->char('currency', 3);
            $table->text('assumptions')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->decimal('direct_cost_total', 14, 2)->default(0);
            $table->decimal('indirect_cost_total', 14, 2)->default(0);
            $table->decimal('contingency_total', 14, 2)->default(0);
            $table->decimal('estimated_cost_total', 14, 2)->default(0);
            $table->decimal('funding_required_total', 14, 2)->default(0);
            $table->decimal('recoverable_total', 14, 2)->default(0);
            $table->decimal('nonrecoverable_total', 14, 2)->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['inquiry_id', 'version']);
            $table->index(['sales_owner_id', 'status']);
        });

        Schema::create('cost_estimate_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cost_estimate_id')->constrained()->cascadeOnDelete();
            $table->string('category', 48);
            $table->string('description', 180);
            $table->string('supplier', 180)->nullable();
            $table->decimal('quantity', 12, 4);
            $table->string('unit', 32);
            $table->decimal('unit_cost', 14, 4);
            $table->char('currency', 3);
            $table->decimal('exchange_rate_to_estimate', 18, 8)->nullable();
            $table->string('exchange_rate_source', 120)->nullable();
            $table->date('exchange_rate_date')->nullable();
            $table->string('tax_treatment', 12)->default('none');
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->decimal('estimate_currency_total', 14, 2);
            $table->string('cost_type', 12)->default('direct');
            $table->string('cost_payer', 12)->default('company');
            $table->string('recoverability', 16)->default('nonrecoverable');
            $table->boolean('funding_required')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['cost_estimate_id', 'category']);
        });

        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('inquiry_id')->constrained('client_inquiries')->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('forecast_id')->nullable()->constrained('shipment_forecasts')->nullOnDelete();
            $table->foreignId('cost_estimate_id')->constrained()->restrictOnDelete();
            $table->json('cost_snapshot');
            $table->foreignId('sales_owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_quotation_id')->nullable()->constrained('quotations')->restrictOnDelete();
            $table->text('revision_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('draft')->index();
            $table->string('client_contact', 120)->nullable();
            $table->string('service_scope', 240);
            $table->string('origin', 180);
            $table->string('destination', 180);
            $table->string('cargo_description', 220);
            $table->date('planned_window')->nullable();
            $table->char('currency', 3);
            $table->date('valid_until');
            $table->text('terms')->nullable();
            $table->string('payment_terms', 180)->nullable();
            $table->text('exclusions')->nullable();
            $table->text('assumptions')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('customer_remarks')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['inquiry_id', 'version']);
            $table->index(['sales_owner_id', 'status']);
        });

        Schema::create('quotation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('description', 180);
            $table->decimal('quantity', 12, 4);
            $table->string('unit', 32);
            $table->decimal('unit_price', 14, 4);
            $table->string('discount_type', 12)->default('none');
            $table->decimal('discount_value', 14, 4)->default(0);
            $table->string('tax_treatment', 12)->default('none');
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('quotation_approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage', 24);
            $table->string('action', 24);
            $table->text('remarks')->nullable();
            $table->timestamp('acted_at');
            $table->index(['quotation_id', 'stage', 'acted_at']);
        });

        Schema::create('quotation_client_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('quotation_version');
            $table->string('response', 16);
            $table->string('client_representative', 120);
            $table->dateTime('responded_at');
            $table->string('evidence_reference', 500)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['quotation_id', 'responded_at']);
        });

        Schema::create('commercial_baselines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('client_inquiries')->restrictOnDelete();
            $table->foreignId('quotation_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quotation_version');
            $table->json('snapshot');
            $table->timestamp('accepted_at');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['inquiry_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_baselines');
        Schema::dropIfExists('quotation_client_responses');
        Schema::dropIfExists('quotation_approval_steps');
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('cost_estimate_lines');
        Schema::dropIfExists('cost_estimates');
        Schema::dropIfExists('document_sequences');
    }
};
