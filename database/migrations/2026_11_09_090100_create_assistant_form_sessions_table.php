<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_form_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('session_uuid');
            $table->string('form_key', 60);
            $table->json('field_values');
            $table->json('field_order');
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->string('status', 24)->default('collecting');
            $table->unsignedBigInteger('draft_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'updated_at']);
            $table->unique(['user_id', 'session_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_form_sessions');
    }
};
