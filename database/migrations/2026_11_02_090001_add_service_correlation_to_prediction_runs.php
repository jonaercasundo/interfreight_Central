<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_prediction_runs', function (Blueprint $table): void {
            $table->string('service_correlation_id', 100)->nullable()->after('request_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ai_prediction_runs', function (Blueprint $table): void {
            $table->dropIndex(['service_correlation_id']);
            $table->dropColumn('service_correlation_id');
        });
    }
};
