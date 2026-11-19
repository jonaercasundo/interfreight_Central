<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_key', 64);
            $table->string('original_name', 255);
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->string('scan_status', 24)->default('pending');
            $table->string('processing_status', 24)->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'session_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_documents');
    }
};
