<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_capacity_availability', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('origin', 180);
            $table->string('destination', 180);
            $table->string('transport_mode', 16);
            $table->string('service_type', 16);
            $table->date('departure_date');
            $table->decimal('confirmed_capacity', 16, 3);
            $table->decimal('reserved_capacity', 16, 3)->default(0);
            $table->string('capacity_unit', 16);
            $table->timestamp('booking_cutoff')->nullable();
            $table->string('source_type', 32);
            $table->string('source_reference', 180);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['departure_date', 'transport_mode', 'service_type'], 'sales_capacity_route_date_idx');
            $table->index(['deactivated_at', 'valid_from', 'valid_until'], 'sales_capacity_validity_idx');
        });

        Schema::create('sales_capacity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('availability_id')->constrained('sales_capacity_availability')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 24);
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['availability_id', 'created_at'], 'sales_capacity_events_history_idx');
        });

        $permissionNames = [
            'sales.dashboard.view', 'sales.calendar.view', 'sales.availability.view',
            'sales.availability.manage', 'sales.inquiries.approve',
        ];
        foreach ($permissionNames as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $sales = Role::query()->where('name', 'Sales')->where('guard_name', 'web')->first();
        $sales?->givePermissionTo(['sales.dashboard.view', 'sales.calendar.view', 'sales.availability.view']);

        $salesManager = Role::query()->where('name', 'Sales Manager')->where('guard_name', 'web')->first();
        $salesManager?->givePermissionTo(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view', 'profile.view', 'profile.update', 'assistant.use', 'department.sales.view',
            'shipments.view', 'shipments.create', 'shipments.timeline.view',
            'clients.view', 'clients.create', 'clients.update',
            'inquiries.view', 'inquiries.create', 'inquiries.update', 'inquiries.submit', 'inquiries.review', 'inquiries.cancel',
            'forecasts.view', 'forecasts.create', 'forecasts.update', 'forecasts.withdraw',
            'cost-estimates.view', 'cost-estimates.create', 'cost-estimates.update', 'cost-estimates.submit', 'cost-estimates.history.view',
            'quotations.view', 'quotations.create', 'quotations.update', 'quotations.submit', 'quotations.issue',
            'quotations.client-response.record', 'quotations.revise', 'quotations.history.view', 'quotations.export', 'margins.view',
            'sales.dashboard.view', 'sales.calendar.view', 'sales.availability.view', 'sales.availability.manage', 'sales.inquiries.approve',
        ])->get());

        Role::query()->where('name', 'Administrator')->where('guard_name', 'web')->first()
            ?->givePermissionTo(Permission::whereIn('name', $permissionNames)->get());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_capacity_events');
        Schema::dropIfExists('sales_capacity_availability');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', [
            'sales.dashboard.view', 'sales.calendar.view', 'sales.availability.view',
            'sales.availability.manage', 'sales.inquiries.approve',
        ])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
