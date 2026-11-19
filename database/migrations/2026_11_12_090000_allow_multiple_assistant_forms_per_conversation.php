<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_form_sessions', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'conversation_uuid']);
            $table->index(['user_id', 'conversation_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_form_sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'conversation_uuid']);
            $table->unique(['user_id', 'conversation_uuid']);
        });
    }
};
