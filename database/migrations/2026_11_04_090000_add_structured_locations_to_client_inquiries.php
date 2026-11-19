<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            foreach (['origin', 'destination'] as $prefix) {
                $table->char("{$prefix}_country_code", 2)->nullable()->after($prefix);
                $table->string("{$prefix}_country_name", 100)->nullable()->after("{$prefix}_country_code");
                $table->string("{$prefix}_location_id", 32)->nullable()->after("{$prefix}_country_name");
                $table->string("{$prefix}_location_type", 24)->nullable()->after("{$prefix}_location_id");
                $table->string("{$prefix}_location_code_system", 16)->nullable()->after("{$prefix}_location_type");
                $table->string("{$prefix}_location_name", 180)->nullable()->after("{$prefix}_location_code_system");
                $table->string("{$prefix}_custom_details", 180)->nullable()->after("{$prefix}_location_name");
            }
            $table->index(['origin_country_code', 'origin_location_id'], 'inquiries_origin_location_index');
            $table->index(['destination_country_code', 'destination_location_id'], 'inquiries_destination_location_index');
        });
    }

    public function down(): void
    {
        Schema::table('client_inquiries', function (Blueprint $table): void {
            $table->dropIndex('inquiries_origin_location_index');
            $table->dropIndex('inquiries_destination_location_index');
            foreach (['origin', 'destination'] as $prefix) {
                $table->dropColumn([
                    "{$prefix}_country_code", "{$prefix}_country_name", "{$prefix}_location_id",
                    "{$prefix}_location_type", "{$prefix}_location_code_system", "{$prefix}_location_name",
                    "{$prefix}_custom_details",
                ]);
            }
        });
    }
};
