<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cost_estimates', 'revision_reason')) {
            Schema::table('cost_estimates', function (Blueprint $table): void {
                $table->text('revision_reason')->nullable();
            });
        }
        if (! Schema::hasColumn('quotations', 'revision_reason')) {
            Schema::table('quotations', function (Blueprint $table): void {
                $table->text('revision_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotations', 'revision_reason')) {
            Schema::table('quotations', function (Blueprint $table): void {
                $table->dropColumn('revision_reason');
            });
        }
        if (Schema::hasColumn('cost_estimates', 'revision_reason')) {
            Schema::table('cost_estimates', function (Blueprint $table): void {
                $table->dropColumn('revision_reason');
            });
        }
    }
};
