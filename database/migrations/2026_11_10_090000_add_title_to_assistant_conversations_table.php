<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table): void {
            $table->string('title', 120)->nullable()->after('conversation_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table): void {
            $table->dropColumn('title');
        });
    }
};
