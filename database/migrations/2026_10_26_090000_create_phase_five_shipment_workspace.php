<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->foreignId('inquiry_id')->nullable()->after('client_id')->constrained('client_inquiries')->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->after('inquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_baseline_id')->nullable()->after('quotation_id')->constrained('commercial_baselines')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->after('commercial_baseline_id')->constrained('users')->nullOnDelete();
            $table->unique('quotation_id');
            $table->string('job_number', 24)->nullable()->unique();
            $table->string('creation_key', 120)->nullable()->unique();
            $table->string('originating_department', 24)->nullable()->index();
            $table->string('source_type', 24)->default('legacy')->index();
            $table->string('shipment_type', 24)->nullable();
            $table->string('service_type', 24)->nullable();
            $table->string('lcl_fcl', 8)->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('volume_cbm', 12, 3)->nullable();
            $table->date('planned_departure')->nullable()->index();
            $table->date('actual_departure')->nullable();
            $table->string('master_status', 32)->default('open')->index();
            $table->string('currency', 3)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->index(['master_status', 'planned_departure']);
        });

        Schema::create('shipment_job_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::create('shipment_department_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('department', 24);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['shipment_id', 'department']);
            $table->index(['department', 'status']);
        });

        Schema::create('shipment_timeline_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->string('department', 24)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 48)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('occurred_at')->index();
            $table->json('payload')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('shipment_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('department', 24);
            $table->string('code', 48);
            $table->string('label', 120);
            $table->string('status', 24)->default('pending');
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('actual_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['shipment_id', 'department', 'status']);
        });

        Schema::create('shipment_customs_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_broker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entry_reference', 80)->nullable();
            $table->string('declaration_reference', 80)->nullable();
            $table->json('required_permits')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('inspection_status', 32)->default('not_required');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique('shipment_id');
        });

        Schema::create('shipment_transport_trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('leg_reference', 40)->unique();
            $table->string('origin', 180);
            $table->string('destination', 180);
            $table->string('provider', 180)->nullable();
            $table->string('vehicle', 80)->nullable();
            $table->string('plate_number', 32)->nullable()->index();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('driver_name', 120)->nullable();
            $table->timestamp('scheduled_pickup')->nullable()->index();
            $table->timestamp('actual_pickup')->nullable();
            $table->timestamp('scheduled_delivery')->nullable()->index();
            $table->timestamp('actual_delivery')->nullable();
            $table->string('status', 32)->default('pending_assignment')->index();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            // Keep the identifier within MySQL's 64-character limit.
            $table->index(['driver_id', 'scheduled_pickup', 'scheduled_delivery'], 'transport_driver_schedule_idx');
        });

        Schema::create('shipment_forwarding_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('booking_reference', 80)->nullable()->unique();
            $table->string('carrier', 180)->nullable();
            $table->string('vessel', 180)->nullable();
            $table->string('voyage_number', 80)->nullable();
            $table->string('flight_number', 80)->nullable();
            $table->string('port_of_loading', 180)->nullable();
            $table->string('port_of_discharge', 180)->nullable();
            $table->timestamp('etd')->nullable();
            $table->timestamp('eta')->nullable();
            $table->timestamp('actual_departure')->nullable();
            $table->timestamp('actual_arrival')->nullable();
            $table->string('transport_document_reference', 100)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('shipment_containers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('shipment_forwarding_bookings')->nullOnDelete();
            $table->string('container_number', 15)->index();
            $table->string('size_type', 32)->nullable();
            $table->string('seal_number', 40)->nullable();
            $table->string('carrier', 180)->nullable();
            $table->string('status', 32)->default('planned');
            $table->timestamp('gate_in_at')->nullable();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('discharged_at')->nullable();
            $table->timestamp('gate_out_at')->nullable();
            $table->timestamp('empty_returned_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['shipment_id', 'container_number']);
        });

        Schema::create('shipment_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transport_trip_id')->nullable()->constrained('shipment_transport_trips')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivered_to', 180)->nullable();
            $table->string('receiving_representative', 180)->nullable();
            $table->string('location', 180)->nullable();
            $table->decimal('quantity_delivered', 12, 3)->nullable();
            $table->text('shortages_damages')->nullable();
            $table->boolean('recipient_acknowledged')->default(false);
            $table->string('pod_status', 32)->default('pending');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'pod_status']);
        });

        Schema::create('shipment_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained('shipment_deliveries')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 48)->index();
            $table->string('original_name', 255);
            $table->string('disk_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('replaces_document_id')->nullable()->constrained('shipment_documents')->nullOnDelete();
            $table->date('expires_on')->nullable();
            $table->string('verification_status', 24)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'category', 'version']);
        });

        Schema::create('shipment_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department', 24)->nullable();
            $table->string('severity', 16)->default('normal');
            $table->string('status', 20)->default('open')->index();
            $table->string('title', 180);
            $table->text('description');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'status', 'severity']);
        });

        Schema::create('shipment_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 24)->default('master');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['shipment_id', 'created_at']);
        });

        $sequences = [];
        foreach (DB::table('shipments')->whereNull('job_number')->orderBy('id')->get(['id', 'status', 'created_at']) as $shipment) {
            $year = (int) date('Y', strtotime($shipment->created_at ?: now()));
            $sequences[$year] = ($sequences[$year] ?? 0) + 1;
            $jobNumber = sprintf('IFC-%d-%06d', $year, $sequences[$year]);
            $legacyStatus = strtolower((string) $shipment->status);
            $masterStatus = match ($legacyStatus) {
                'delivered' => 'operationally_completed',
                'delayed', 'customs', 'in transit' => 'in_progress',
                'cancelled' => 'cancelled',
                'booked' => 'open',
                default => 'in_progress',
            };
            DB::table('shipments')->where('id', $shipment->id)->update(['job_number' => $jobNumber, 'master_status' => $masterStatus]);
        }
        foreach ($sequences as $year => $lastValue) {
            DB::table('shipment_job_sequences')->updateOrInsert(['year' => $year], ['last_value' => $lastValue, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->grantPermissions();
    }

    private function grantPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $permissions = [
            'shipments.create', 'shipments.update', 'shipments.assign', 'shipments.cancel', 'shipments.timeline.view', 'shipments.documents.view', 'shipments.documents.upload',
            'operations.jobs.view', 'operations.jobs.manage', 'operations.customs.manage', 'operations.documents.verify',
            'transport.jobs.view', 'transport.assignments.manage', 'transport.dispatch.manage', 'transport.deliveries.manage', 'transport.pod.upload', 'transport.pod.verify',
            'forwarding.jobs.view', 'forwarding.bookings.manage', 'forwarding.containers.manage', 'forwarding.milestones.manage',
            'shipment.exceptions.view', 'shipment.exceptions.manage', 'shipment.reconciliation.view',
        ];
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $grants = [
            'Administrator' => ['shipments.view', 'shipments.timeline.view', 'shipments.documents.view', 'shipment.reconciliation.view'],
            'Sales' => ['shipments.view', 'shipments.create', 'shipments.timeline.view'],
            'Operations' => ['shipments.view', 'shipments.create', 'shipments.update', 'shipments.assign', 'shipments.cancel', 'shipments.timeline.view', 'shipments.documents.view', 'shipments.documents.upload', 'operations.jobs.view', 'operations.jobs.manage', 'operations.customs.manage', 'operations.documents.verify', 'transport.pod.verify', 'shipment.exceptions.view', 'shipment.exceptions.manage', 'shipment.reconciliation.view'],
            'Transport' => ['shipments.view', 'shipments.create', 'shipments.update', 'shipments.timeline.view', 'shipments.documents.view', 'shipments.documents.upload', 'transport.jobs.view', 'transport.assignments.manage', 'transport.dispatch.manage', 'transport.deliveries.manage', 'transport.pod.upload', 'shipment.exceptions.view', 'shipment.exceptions.manage'],
            'Forwarding' => ['shipments.view', 'shipments.create', 'shipments.update', 'shipments.timeline.view', 'shipments.documents.view', 'shipments.documents.upload', 'forwarding.jobs.view', 'forwarding.bookings.manage', 'forwarding.containers.manage', 'forwarding.milestones.manage', 'shipment.exceptions.view', 'shipment.exceptions.manage'],
            'Accounting' => ['shipments.view', 'shipments.timeline.view'],
            'Finance' => ['shipments.view', 'shipments.timeline.view'],
            'Executive' => ['shipments.view', 'shipments.timeline.view', 'shipment.reconciliation.view', 'operations.jobs.view', 'transport.jobs.view', 'forwarding.jobs.view'],
        ];
        foreach ($grants as $roleName => $names) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo($names);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_status_history');
        Schema::dropIfExists('shipment_exceptions');
        Schema::dropIfExists('shipment_documents');
        Schema::dropIfExists('shipment_deliveries');
        Schema::dropIfExists('shipment_containers');
        Schema::dropIfExists('shipment_forwarding_bookings');
        Schema::dropIfExists('shipment_transport_trips');
        Schema::dropIfExists('shipment_customs_processes');
        Schema::dropIfExists('shipment_milestones');
        Schema::dropIfExists('shipment_timeline_events');
        Schema::dropIfExists('shipment_department_assignments');
        Schema::dropIfExists('shipment_job_sequences');
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropForeign(['client_id']);
            $table->dropForeign(['inquiry_id']);
            $table->dropForeign(['quotation_id']);
            $table->dropForeign(['commercial_baseline_id']);
            $table->dropForeign(['created_by']);
            $table->dropUnique(['quotation_id']);
            $table->dropUnique(['job_number']);
            $table->dropUnique(['creation_key']);
            $table->dropIndex(['originating_department']);
            $table->dropIndex(['source_type']);
            $table->dropIndex(['master_status']);
            $table->dropIndex(['master_status', 'planned_departure']);
            $table->dropColumn(['client_id', 'inquiry_id', 'quotation_id', 'commercial_baseline_id', 'created_by', 'job_number', 'creation_key', 'originating_department', 'source_type', 'shipment_type', 'service_type', 'lcl_fcl', 'quantity', 'volume_cbm', 'planned_departure', 'actual_departure', 'master_status', 'currency', 'cancellation_reason', 'archived_at', 'lock_version']);
        });
    }
};
