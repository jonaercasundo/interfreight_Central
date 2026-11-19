<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('conversation_uuid')->unique();
            $table->string('module', 40)->nullable();
            $table->string('current_path', 120)->nullable();
            $table->json('messages');
            $table->timestamp('last_activity_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'expires_at', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversations');
    }
};
