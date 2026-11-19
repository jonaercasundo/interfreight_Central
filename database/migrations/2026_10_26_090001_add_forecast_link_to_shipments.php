<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipments', 'forecast_id')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->foreignId('forecast_id')->nullable()->after('inquiry_id')->constrained('shipment_forecasts')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'forecast_id')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->dropForeign(['forecast_id']);
                $table->dropColumn('forecast_id');
            });
        }
    }
};
