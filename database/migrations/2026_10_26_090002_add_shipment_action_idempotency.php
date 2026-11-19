<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipment_milestones', 'idempotency_key')) {
            Schema::table('shipment_milestones', function (Blueprint $table): void {
                $table->string('idempotency_key', 120)->nullable()->unique();
            });
        }
        if (! Schema::hasColumn('shipment_documents', 'upload_key')) {
            Schema::table('shipment_documents', function (Blueprint $table): void {
                $table->string('upload_key', 120)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipment_documents', 'upload_key')) {
            Schema::table('shipment_documents', function (Blueprint $table): void {
                $table->dropUnique(['upload_key']);
                $table->dropColumn('upload_key');
            });
        }
        if (Schema::hasColumn('shipment_milestones', 'idempotency_key')) {
            Schema::table('shipment_milestones', function (Blueprint $table): void {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
