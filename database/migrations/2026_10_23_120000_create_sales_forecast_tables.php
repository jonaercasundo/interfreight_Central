<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique()->nullable();
            $table->string('company_name', 180);
            $table->string('contact_name', 120)->nullable();
            $table->string('email', 254)->nullable()->index();
            $table->string('phone', 50)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('client_type', 12);
            $table->foreignId('sales_owner_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['sales_owner_id', 'company_name']);
        });

        Schema::create('client_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique()->nullable();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_owner_id')->constrained('users')->restrictOnDelete();
            $table->string('service_type', 16);
            $table->string('transport_mode', 16);
            $table->string('origin', 180);
            $table->string('destination', 180);
            $table->string('cargo_description', 220);
            $table->decimal('estimated_weight_kg', 12, 2)->nullable();
            $table->decimal('estimated_volume_cbm', 10, 2)->nullable();
            $table->unsignedSmallInteger('estimated_container_count')->nullable();
            $table->string('container_type', 30)->nullable();
            $table->string('commodity_category', 100)->nullable();
            $table->text('special_requirements')->nullable();
            $table->date('requested_ship_date')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['sales_owner_id', 'status']);
        });

        Schema::create('shipment_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('client_inquiries')->restrictOnDelete();
            $table->foreignId('sales_owner_id')->constrained('users')->restrictOnDelete();
            $table->date('forecast_month');
            $table->unsignedSmallInteger('expected_shipment_count')->default(1);
            $table->string('confidence', 20)->default('tentative');
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['inquiry_id', 'forecast_month']);
            $table->index(['forecast_month', 'status', 'confidence']);
            $table->index(['sales_owner_id', 'forecast_month']);
        });

        Schema::create('forecast_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('forecast_id')->constrained('shipment_forecasts')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 30);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamps();
            $table->index(['forecast_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_revisions');
        Schema::dropIfExists('shipment_forecasts');
        Schema::dropIfExists('client_inquiries');
        Schema::dropIfExists('clients');
    }
};
