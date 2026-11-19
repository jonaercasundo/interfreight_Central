<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('customer_name');
            $table->string('origin');
            $table->string('destination');
            $table->string('mode');
            $table->string('status')->default('Booked')->index();
            $table->string('cargo');
            $table->decimal('weight_kg', 12, 2);
            $table->date('estimated_delivery')->nullable();
            $table->decimal('freight_cost', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
