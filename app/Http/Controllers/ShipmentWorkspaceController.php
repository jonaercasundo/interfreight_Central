<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\ShipmentDepartmentAssignment;
use App\Models\ShipmentDocument;
use App\Models\ShipmentException;
use App\Models\ShipmentMilestone;
use App\Models\ShipmentStatusHistory;
use App\Models\ShipmentTimelineEvent;
use App\Models\ShipmentTransportTrip;
use App\Models\User;
use App\Services\DocumentScanService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ShipmentWorkspaceController extends Controller
{
    private const DEPARTMENTS = ['Operations', 'Transport', 'Forwarding'];

    public function options(Request $request)
    {
        abort_unless($request->user()->can('shipments.create'), 403);
        $clients = DB::table('clients')->select('id', 'company_name', 'client_type', 'sales_owner_id')->when($request->user()->hasRole('Sales'), fn ($query) => $query->where('sales_owner_id', $request->user()->id))->orderBy('company_name')->limit(500)->get();
        $quotations = DB::table('quotations')->join('commercial_baselines', 'commercial_baselines.quotation_id', '=', 'quotations.id')->join('clients', 'clients.id', '=', 'quotations.client_id')->where('commercial_baselines.is_active', true)->when($request->user()->hasRole('Sales'), fn ($query) => $query->where('quotations.sales_owner_id', $request->user()->id))->select('quotations.id', 'quotations.reference', 'quotations.client_id', 'quotations.inquiry_id', 'quotations.currency', 'quotations.origin', 'quotations.destination', 'quotations.cargo_description', 'clients.company_name')->orderByDesc('quotations.issued_at')->limit(500)->get();
        $usersByDepartment = [];
        foreach (self::DEPARTMENTS as $department) {
            $usersByDepartment[$department] = User::role($department)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        }

        return response()->json(['clients' => $clients, 'accepted_quotations' => $quotations, 'departments' => self::DEPARTMENTS, 'users_by_department' => $usersByDepartment]);
    }

    public function index(Request $request)
    {
        $query = $this->visibleShipments($request)->with(['assignments', 'quotation:id,reference', 'inquiry:id,reference'])->when($request->filled('search'), function ($query) use ($request): void {
            $search = '%'.$request->string('search')->trim().'%';
            $query->where(fn ($q) => $q->where('job_number', 'like', $search)->orWhere('reference', 'like', $search)->orWhere('customer_name', 'like', $search)->orWhere('origin', 'like', $search)->orWhere('destination', 'like', $search));
        })->when($request->filled('status'), fn ($query) => $query->where('master_status', $request->string('status')))
            ->when($request->filled('department'), fn ($query) => $query->whereHas('assignments', fn ($q) => $q->where('department', $request->string('department'))))
            ->when($request->filled('mode'), fn ($query) => $query->where('mode', $request->string('mode')))
            ->when($request->boolean('exceptions'), fn ($query) => $query->whereHas('exceptions', fn ($q) => $q->where('status', 'open')))
            ->whereNull('archived_at')->orderByDesc('id');

        return $query->paginate(min(100, max(1, $request->integer('per_page', 15))));
    }

    public function dashboard(Request $request)
    {
        $base = $this->visibleShipments($request)->whereNull('archived_at');
        $department = $this->userDepartment($request);
        $jobs = $base->clone();
        $jobIds = fn () => (clone $jobs)->select('shipments.id');
        $trips = fn () => DB::table('shipment_transport_trips')->whereIn('shipment_id', $jobIds());
        $bookings = fn () => DB::table('shipment_forwarding_bookings')->whereIn('shipment_id', $jobIds());
        $deliveries = fn () => DB::table('shipment_deliveries')->whereIn('shipment_id', $jobIds());
        $containers = fn () => DB::table('shipment_containers')->whereIn('shipment_id', $jobIds());

        return response()->json(['data' => [
            'scope' => $department ?? 'shared',
            'master_jobs' => (clone $jobs)->count(),
            'active_jobs' => (clone $jobs)->whereIn('master_status', ['open', 'in_progress', 'on_hold'])->count(),
            'operationally_completed' => (clone $jobs)->where('master_status', 'operationally_completed')->count(),
            'exceptions' => (clone $jobs)->whereHas('exceptions', fn ($q) => $q->where('status', 'open'))->count(),
            'pending_customs' => in_array($department, [null, 'Operations'], true) ? (clone $jobs)->where(function ($q): void {
                $q->whereDoesntHave('customsProcess')->orWhereHas('customsProcess', fn ($customs) => $customs->whereNotIn('status', ['released', 'not_required']));
            })->count() : null,
            'pending_documents' => (clone $jobs)->whereHas('documents', fn ($q) => $q->where('verification_status', 'pending'))->count(),
            'processing_delays' => (clone $jobs)->whereHas('exceptions', fn ($q) => $q->where('status', 'open')->whereIn('severity', ['high', 'critical']))->count(),
            'pending_release' => in_array($department, [null, 'Operations'], true) ? (clone $jobs)->whereHas('customsProcess', fn ($q) => $q->whereIn('status', ['submitted', 'inspection', 'on_hold']))->count() : null,
            'scheduled_pickups' => in_array($department, [null, 'Transport'], true) ? $trips()->where('status', 'scheduled')->whereDate('scheduled_pickup', '>=', now()->toDateString())->count() : null,
            'dispatched_trips' => in_array($department, [null, 'Transport'], true) ? $trips()->whereIn('status', ['dispatched', 'picked_up'])->count() : null,
            'in_transit_trips' => in_array($department, [null, 'Transport'], true) ? $trips()->where('status', 'in_transit')->count() : null,
            'pending_pod' => in_array($department, [null, 'Transport'], true) ? $deliveries()->whereIn('pod_status', ['pending', 'uploaded', 'under_review', 'rejected', 'replacement_requested'])->count() : null,
            'pending_bookings' => in_array($department, [null, 'Forwarding'], true) ? $bookings()->where('status', 'pending')->count() : null,
            'confirmed_bookings' => in_array($department, [null, 'Forwarding'], true) ? $bookings()->where('status', 'confirmed')->count() : null,
            'upcoming_departures' => in_array($department, [null, 'Forwarding'], true) ? $bookings()->whereNotNull('etd')->whereDate('etd', '>=', now()->toDateString())->where('status', 'confirmed')->count() : null,
            'upcoming_arrivals' => in_array($department, [null, 'Forwarding'], true) ? $bookings()->whereNotNull('eta')->whereDate('eta', '>=', now()->toDateString())->where('status', 'confirmed')->count() : null,
            'direct_transport_jobs' => $department === 'Transport' ? (clone $jobs)->where('source_type', 'direct')->where('originating_department', 'Transport')->count() : null,
            'container_records' => in_array($department, [null, 'Forwarding'], true) ? $containers()->count() : null,
            'recent' => (clone $jobs)->with('assignments')->latest()->limit(8)->get(),
            'counts_note' => 'Global counts are distinct master shipment records; trips, containers and deliveries are separate operational records.',
        ]]);
    }

    public function show(Request $request, Shipment $shipment)
    {
        abort_unless($this->visibleShipments($request)->whereKey($shipment->id)->exists(), 403);

        $shipment->load(['client:id,company_name', 'inquiry:id,reference', 'forecast:id,forecast_month,confidence,expected_shipment_count,status', 'quotation:id,reference,status', 'baseline:id,quotation_id,accepted_at', 'assignments.assignee:id,name', 'milestones', 'customsProcess', 'trips', 'bookings', 'containers', 'deliveries', 'documents', 'exceptions', 'statusHistory', 'events.actor:id,name']);

        return response()->json(['data' => $shipment, 'financial_readiness' => $this->financialReadiness($shipment)]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('shipments.create'), 403);
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['accepted_quotation', 'direct'])], 'quotation_id' => 'required_if:source_type,accepted_quotation|nullable|integer|exists:quotations,id',
            'client_id' => 'required_if:source_type,direct|nullable|integer|exists:clients,id', 'originating_department' => ['required_if:source_type,direct', 'nullable', Rule::in(self::DEPARTMENTS)],
            'inquiry_id' => 'nullable|integer|exists:client_inquiries,id', 'forecast_id' => 'nullable|integer|exists:shipment_forecasts,id', 'origin' => 'required|string|max:180', 'destination' => 'required|string|max:180',
            'mode' => ['required', Rule::in(['Sea', 'Air', 'Land', 'Multimodal', 'Ocean', 'Road', 'Rail'])], 'shipment_type' => ['required', Rule::in(['Import', 'Export', 'Domestic'])],
            'service_type' => ['required', Rule::in(['Operations', 'Transport', 'Forwarding', 'Full Service'])], 'lcl_fcl' => ['nullable', Rule::in(['LCL', 'FCL'])],
            'cargo' => 'required_if:source_type,direct|nullable|string|max:220', 'weight_kg' => 'nullable|numeric|min:0|max:9999999999.99', 'quantity' => 'nullable|numeric|min:0|max:999999999.999', 'volume_cbm' => 'nullable|numeric|min:0|max:999999999.999',
            'estimated_delivery' => 'nullable|date', 'planned_departure' => 'nullable|date', 'currency' => 'nullable|string|size:3', 'notes' => 'nullable|string|max:4000',
            'departments' => 'sometimes|array', 'departments.*' => ['string', Rule::in(self::DEPARTMENTS)], 'idempotency_key' => 'nullable|string|max:120',
        ]);
        $key = $request->header('Idempotency-Key') ?? $data['idempotency_key'] ?? null;
        abort_if($key !== null && (strlen($key) > 120 || trim($key) === ''), 422, 'The idempotency key must contain 1 to 120 characters.');
        if ($key && ($existing = Shipment::where('creation_key', $key)->first())) {
            return response()->json(['data' => $existing, 'idempotent_replay' => true], 200);
        }
        if (! $request->user()->can('shipments.assign') && ! empty($data['departments'])) {
            abort(403, 'Department assignments require shipment assignment authorization.');
        }

        $quotation = null;
        $inquiry = null;
        $baseline = null;
        $clientId = $data['client_id'] ?? null;
        $department = $data['originating_department'] ?? null;
        if ($data['source_type'] === 'accepted_quotation') {
            abort_unless($request->user()->hasRole('Sales') || $request->user()->hasAnyRole(self::DEPARTMENTS), 403);
            $quotation = DB::table('quotations')->where('id', $data['quotation_id'])->first();
            $baseline = DB::table('commercial_baselines')->where('quotation_id', $quotation->id)->where('is_active', true)->first();
            abort_unless($baseline, 422, 'A shipment can use a quotation only after client acceptance creates an active commercial baseline.');
            abort_unless(! $request->user()->hasRole('Sales') || (int) $quotation->sales_owner_id === (int) $request->user()->id, 403, 'Sales users may create shipments only from their own accepted quotations.');
            $inquiry = DB::table('client_inquiries')->where('id', $quotation->inquiry_id)->first();
            abort_if(Shipment::where('quotation_id', $quotation->id)->exists(), 409, 'A shipment already exists for this accepted quotation.');
            $clientId = $quotation->client_id;
            $department = 'Sales';
            $data['forecast_id'] = $quotation->forecast_id;
            $data['cargo'] = $data['cargo'] ?: $quotation->cargo_description;
        } else {
            abort_unless($request->user()->hasRole($department) && $request->user()->can('department.'.strtolower($department).'.view'), 403, 'Direct shipments may only be originated by an authorized Operations, Transport, or Forwarding user.');
            if (! empty($data['inquiry_id'])) {
                $inquiry = DB::table('client_inquiries')->where('id', $data['inquiry_id'])->first();
                abort_unless((int) $inquiry->client_id === (int) $clientId, 422, 'The inquiry must belong to the selected client.');
            }
            if (! empty($data['forecast_id'])) {
                $forecast = DB::table('shipment_forecasts')->where('id', $data['forecast_id'])->where('status', 'active')->first();
                abort_unless($forecast, 422, 'Select an active forecast.');
                $inquiry ??= DB::table('client_inquiries')->where('id', $forecast->inquiry_id)->first();
                abort_unless((int) $forecast->inquiry_id === (int) ($inquiry->id ?? 0) && (int) $inquiry->client_id === (int) $clientId, 422, 'The forecast must belong to the selected client inquiry.');
            }
        }
        if ($inquiry) {
            abort_unless((int) $inquiry->client_id === (int) $clientId, 422, 'The source inquiry does not match the selected client.');
        }
        $clientName = DB::table('clients')->where('id', $clientId)->value('company_name');
        abort_unless($clientName, 422, 'Select a valid client.');
        try {
            $shipment = DB::transaction(function () use ($request, $data, $quotation, $baseline, $clientId, $clientName, $department, $inquiry, $key): Shipment {
                $year = now()->year;
                $existingMax = 0;
                foreach (DB::table('shipments')->where('job_number', 'like', 'IFC-'.$year.'-%')->pluck('job_number') as $legacyNumber) {
                    if (preg_match('/^IFC-'.$year.'-(\d{6})$/', $legacyNumber, $matches)) {
                        $existingMax = max($existingMax, (int) $matches[1]);
                    }
                }
                DB::table('shipment_job_sequences')->insertOrIgnore(['year' => $year, 'last_value' => $existingMax, 'created_at' => now(), 'updated_at' => now()]);
                $sequence = DB::table('shipment_job_sequences')->where('year', $year)->lockForUpdate()->first();
                $next = $sequence->last_value + 1;
                DB::table('shipment_job_sequences')->where('year', $year)->update(['last_value' => $next, 'updated_at' => now()]);
                $jobNumber = sprintf('IFC-%d-%06d', $year, $next);
                $shipment = Shipment::create([
                    'reference' => $jobNumber, 'job_number' => $jobNumber, 'creation_key' => $key, 'client_id' => $clientId,
                    'inquiry_id' => $inquiry->id ?? $data['inquiry_id'] ?? null, 'forecast_id' => $data['forecast_id'] ?? null, 'quotation_id' => $quotation->id ?? null,
                    'commercial_baseline_id' => $baseline->id ?? null, 'created_by' => $request->user()->id,
                    'customer_name' => $clientName, 'origin' => $data['origin'], 'destination' => $data['destination'], 'mode' => $data['mode'],
                    'status' => 'Open', 'master_status' => 'open', 'source_type' => $data['source_type'], 'originating_department' => $department,
                    'shipment_type' => $data['shipment_type'], 'service_type' => $data['service_type'], 'lcl_fcl' => $data['lcl_fcl'] ?? null,
                    'cargo' => $data['cargo'] ?? $quotation->cargo_description ?? 'Cargo details pending', 'weight_kg' => $data['weight_kg'] ?? 0, 'quantity' => $data['quantity'] ?? null, 'volume_cbm' => $data['volume_cbm'] ?? null,
                    'estimated_delivery' => $data['estimated_delivery'] ?? null, 'planned_departure' => $data['planned_departure'] ?? null,
                    'freight_cost' => 0, 'currency' => $data['currency'] ?? $quotation->currency ?? null, 'notes' => $data['notes'] ?? null,
                ]);
                $departments = array_values(array_unique([...(in_array($department, self::DEPARTMENTS, true) ? [$department] : []), ...($data['departments'] ?? [])]));
                foreach ($departments as $assignedDepartment) {
                    if ($assignedDepartment !== $department) {
                        abort_unless($request->user()->can('shipments.assign'), 403);
                    }
                    ShipmentDepartmentAssignment::create(['shipment_id' => $shipment->id, 'department' => $assignedDepartment, 'assigned_by' => $request->user()->id, 'status' => 'pending']);
                }
                ShipmentStatusHistory::create(['shipment_id' => $shipment->id, 'actor_id' => $request->user()->id, 'scope' => 'master', 'to_status' => 'open', 'reason' => 'Master shipment registered.']);
                $this->recordEvent($shipment, 'job_created', $department, 'shipment', $shipment->id, $request->user()->id, 'Shipment job registered.', $key ? $key.':created' : null);

                return $shipment;
            });
        } catch (QueryException $e) {
            if ($key && ($existing = Shipment::where('creation_key', $key)->first())) {
                return response()->json(['data' => $existing, 'idempotent_replay' => true], 200);
            }
            if ($quotation && Shipment::where('quotation_id', $quotation->id)->exists()) {
                abort(409, 'A shipment already exists for this accepted quotation.');
            }
            throw $e;
        }

        return response()->json(['data' => $shipment->load('assignments'), 'financial_readiness' => $this->financialReadiness($shipment)], 201);
    }

    public function update(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('shipments.update'), 403);
        abort_if($shipment->commercial_baseline_id && $request->exists('quotation_id'), 422, 'Accepted commercial baselines cannot be changed through shipment editing.');
        abort_if(in_array($shipment->master_status, ['cancelled', 'financially_closed'], true), 409, 'This shipment is no longer editable.');
        $data = $request->validate(['origin' => 'sometimes|required|string|max:180', 'destination' => 'sometimes|required|string|max:180', 'estimated_delivery' => 'nullable|date', 'planned_departure' => 'nullable|date', 'notes' => 'nullable|string|max:4000', 'expected_version' => 'required|integer']);
        abort_unless((int) $shipment->lock_version === (int) $data['expected_version'], 409, 'The shipment changed since it was opened. Refresh before editing.');
        unset($data['expected_version']);
        $shipment->update([...$data, 'lock_version' => $shipment->lock_version + 1]);
        $this->recordEvent($shipment, 'shipment_updated', $this->userDepartment($request), 'shipment', $shipment->id, $request->user()->id, 'Editable shipment details updated.');

        return response()->json(['data' => $shipment->refresh()]);
    }

    public function assign(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('shipments.assign'), 403);
        $data = $request->validate(['department' => ['required', Rule::in(self::DEPARTMENTS)], 'assigned_user_id' => 'nullable|integer|exists:users,id', 'status' => ['sometimes', Rule::in(['pending', 'assigned', 'active', 'completed', 'on_hold'])], 'remarks' => 'nullable|string|max:2000']);
        if (! empty($data['assigned_user_id'])) {
            $assignee = User::findOrFail($data['assigned_user_id']);
            abort_unless($assignee->hasRole($data['department']), 422, 'The selected user is not assigned to this department.');
        }
        $assignment = DB::transaction(function () use ($data, $shipment, $request) {
            $assignment = ShipmentDepartmentAssignment::updateOrCreate(['shipment_id' => $shipment->id, 'department' => $data['department']], [...$data, 'assigned_by' => $request->user()->id]);
            $this->recordEvent($shipment, 'department_assigned', $data['department'], 'shipment_department_assignment', $assignment->id, $request->user()->id, $data['remarks'] ?? 'Department assignment updated.');

            return $assignment;
        });

        return response()->json(['data' => $assignment->load('assignee:id,name')]);
    }

    public function status(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('shipments.update'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'on_hold', 'operationally_completed'])], 'reason' => 'required|string|min:3|max:1000', 'expected_version' => 'required|integer']);
        abort_unless((int) $shipment->lock_version === (int) $data['expected_version'], 409);
        $allowed = ['open' => ['in_progress', 'on_hold', 'cancelled'], 'in_progress' => ['on_hold', 'operationally_completed'], 'on_hold' => ['open', 'in_progress', 'cancelled'], 'operationally_completed' => ['in_progress']];
        abort_unless(in_array($data['status'], $allowed[$shipment->master_status] ?? [], true), 422, 'This master status transition is not permitted.');
        DB::transaction(function () use ($shipment, $data, $request): void {
            $before = $shipment->master_status;
            $shipment->update(['master_status' => $data['status'], 'status' => Str::headline($data['status']), 'lock_version' => $shipment->lock_version + 1]);
            ShipmentStatusHistory::create(['shipment_id' => $shipment->id, 'actor_id' => $request->user()->id, 'scope' => 'master', 'from_status' => $before, 'to_status' => $data['status'], 'reason' => $data['reason']]);
            $this->recordEvent($shipment, 'master_status_changed', $this->userDepartment($request), 'shipment_status_history', $shipment->id, $request->user()->id, $data['reason']);
        });

        return response()->json(['data' => $shipment->refresh()]);
    }

    public function cancel(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('shipments.cancel'), 403);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        abort_unless(! in_array($shipment->master_status, ['cancelled', 'financially_closed'], true), 409);
        $before = $shipment->master_status;
        $shipment->update(['master_status' => 'cancelled', 'status' => 'Cancelled', 'cancellation_reason' => $data['reason'], 'lock_version' => $shipment->lock_version + 1]);
        ShipmentStatusHistory::create(['shipment_id' => $shipment->id, 'actor_id' => $request->user()->id, 'scope' => 'master', 'from_status' => $before, 'to_status' => 'cancelled', 'reason' => $data['reason']]);
        $this->recordEvent($shipment, 'shipment_cancelled', $this->userDepartment($request), 'shipment', $shipment->id, $request->user()->id, $data['reason']);

        return response()->json(['data' => $shipment->refresh()]);
    }

    public function milestone(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        $data = $request->validate(['department' => ['required', Rule::in(self::DEPARTMENTS)], 'code' => 'required|string|max:48', 'label' => 'required|string|max:120', 'status' => ['required', Rule::in(['pending', 'in_progress', 'completed', 'delayed', 'cancelled'])], 'planned_at' => 'nullable|date', 'actual_at' => 'nullable|date', 'remarks' => 'nullable|string|max:2000', 'idempotency_key' => 'nullable|string|max:120']);
        $key = $request->header('Idempotency-Key') ?? $data['idempotency_key'] ?? null;
        abort_if($key !== null && (trim($key) === '' || strlen($key) > 120), 422, 'The idempotency key must contain 1 to 120 characters.');
        if ($key && ($existing = ShipmentMilestone::where('idempotency_key', $key)->first())) {
            abort_unless((int) $existing->shipment_id === (int) $shipment->id, 409, 'The idempotency key has already been used for another action.');

            return response()->json(['data' => $existing, 'idempotent_replay' => true]);
        }
        $data['idempotency_key'] = $key;
        $this->authorizeDepartmentMutation($request, $data['department']);
        $milestone = DB::transaction(function () use ($request, $shipment, $data) {
            $milestone = ShipmentMilestone::create([...$data, 'shipment_id' => $shipment->id, 'recorded_by' => $request->user()->id]);
            $this->recordEvent($shipment, 'milestone_'.$data['status'], $data['department'], 'shipment_milestone', $milestone->id, $request->user()->id, $data['remarks'] ?? $data['label'], $data['idempotency_key']);
            $this->departmentStatus($shipment, $data['department'], $data['status']);

            return $milestone;
        });

        return response()->json(['data' => $milestone], 201);
    }

    public function customs(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('operations.customs.manage'), 403);
        $data = $request->validate(['assigned_broker_id' => 'nullable|integer|exists:users,id', 'entry_reference' => 'nullable|string|max:80', 'declaration_reference' => 'nullable|string|max:80', 'required_permits' => 'nullable|array', 'required_permits.*' => 'string|max:120', 'status' => ['required', Rule::in(['pending', 'documents_pending', 'submitted', 'inspection', 'on_hold', 'released', 'not_required'])], 'inspection_status' => ['nullable', Rule::in(['not_required', 'pending', 'scheduled', 'passed', 'failed'])], 'remarks' => 'nullable|string|max:2000']);
        $before = $shipment->customsProcess()->first();
        $data['submitted_at'] = $data['status'] === 'submitted' ? ($before?->submitted_at ?? now()) : $before?->submitted_at;
        $data['released_at'] = $data['status'] === 'released' ? ($before?->released_at ?? now()) : null;
        $process = $shipment->customsProcess()->updateOrCreate(['shipment_id' => $shipment->id], $data);
        $this->recordEvent($shipment, 'customs_'.$data['status'], 'Operations', 'shipment_customs_process', $process->id, $request->user()->id, $data['remarks'] ?? 'Customs status updated.');

        return response()->json(['data' => $process]);
    }

    public function trip(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('transport.assignments.manage'), 403);
        $data = $request->validate(['origin' => 'required|string|max:180', 'destination' => 'required|string|max:180', 'provider' => 'nullable|string|max:180', 'vehicle' => 'nullable|string|max:80', 'plate_number' => 'nullable|string|max:32', 'driver_id' => 'nullable|integer|exists:users,id', 'driver_name' => 'nullable|string|max:120', 'scheduled_pickup' => 'nullable|date', 'scheduled_delivery' => 'nullable|date|after_or_equal:scheduled_pickup', 'remarks' => 'nullable|string|max:2000']);
        $this->assertNoScheduleConflict($data);
        $number = $shipment->trips()->count() + 1;
        $trip = $shipment->trips()->create([...$data, 'leg_reference' => $shipment->job_number.'-TR'.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
        $this->recordEvent($shipment, 'transport_leg_created', 'Transport', 'shipment_transport_trip', $trip->id, $request->user()->id, 'Transport leg scheduled.');

        return response()->json(['data' => $trip], 201);
    }

    public function tripStatus(Request $request, ShipmentTransportTrip $trip)
    {
        abort_unless($this->visibleShipments($request)->whereKey($trip->shipment_id)->exists(), 403);
        abort_unless($request->user()->can('transport.dispatch.manage'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['pending_assignment', 'scheduled', 'dispatched', 'picked_up', 'in_transit', 'delivered', 'pod_pending', 'pod_verified', 'cancelled'])], 'actual_pickup' => 'nullable|date', 'actual_delivery' => 'nullable|date', 'remarks' => 'nullable|string|max:2000', 'expected_version' => 'required|integer']);
        abort_unless((int) $trip->lock_version === (int) $data['expected_version'], 409);
        $next = ['pending_assignment' => ['scheduled', 'cancelled'], 'scheduled' => ['dispatched', 'cancelled'], 'dispatched' => ['picked_up', 'cancelled'], 'picked_up' => ['in_transit'], 'in_transit' => ['delivered'], 'delivered' => ['pod_pending', 'pod_verified'], 'pod_pending' => ['pod_verified'], 'pod_verified' => [], 'cancelled' => []];
        abort_unless($trip->status === $data['status'] || in_array($data['status'], $next[$trip->status] ?? [], true), 422, 'This transport transition is not permitted.');
        $trip->update([...collect($data)->except('expected_version')->all(), 'lock_version' => $trip->lock_version + 1]);
        $this->recordEvent($trip->shipment, 'transport_'.$data['status'], 'Transport', 'shipment_transport_trip', $trip->id, $request->user()->id, $data['remarks'] ?? 'Transport trip updated.');

        return response()->json(['data' => $trip->refresh()]);
    }

    public function updateTrip(Request $request, ShipmentTransportTrip $trip)
    {
        abort_unless($request->user()->can('transport.assignments.manage'), 403);
        abort_unless($this->visibleShipments($request)->whereKey($trip->shipment_id)->exists(), 403);
        $data = $request->validate(['origin' => 'sometimes|required|string|max:180', 'destination' => 'sometimes|required|string|max:180', 'provider' => 'sometimes|nullable|string|max:180', 'vehicle' => 'sometimes|nullable|string|max:80', 'plate_number' => 'sometimes|nullable|string|max:32', 'driver_id' => 'sometimes|nullable|integer|exists:users,id', 'driver_name' => 'sometimes|nullable|string|max:120', 'scheduled_pickup' => 'sometimes|nullable|date', 'scheduled_delivery' => 'sometimes|nullable|date|after_or_equal:scheduled_pickup', 'remarks' => 'sometimes|nullable|string|max:2000', 'expected_version' => 'required|integer']);
        abort_unless((int) $trip->lock_version === (int) $data['expected_version'], 409);
        $this->assertNoScheduleConflict(array_merge($trip->only(['driver_id', 'plate_number', 'scheduled_pickup', 'scheduled_delivery']), $data), $trip->id);
        unset($data['expected_version']);
        DB::transaction(function () use ($trip, $data, $request): void {
            ShipmentTransportTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $trip->update([...$data, 'lock_version' => $trip->lock_version + 1]);
            $this->recordEvent($trip->shipment, 'transport_schedule_changed', 'Transport', 'shipment_transport_trip', $trip->id, $request->user()->id, 'Planned transport assignments or schedule changed.');
        });

        return response()->json(['data' => $trip->refresh()]);
    }

    public function booking(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('forwarding.bookings.manage'), 403);
        $data = $request->validate(['booking_reference' => 'nullable|string|max:80', 'carrier' => 'nullable|string|max:180', 'vessel' => 'nullable|string|max:180', 'voyage_number' => 'nullable|string|max:80', 'flight_number' => 'nullable|string|max:80', 'port_of_loading' => 'nullable|string|max:180', 'port_of_discharge' => 'nullable|string|max:180', 'etd' => 'nullable|date', 'eta' => 'nullable|date|after_or_equal:etd', 'transport_document_reference' => 'nullable|string|max:100', 'status' => ['required', Rule::in(['pending', 'confirmed', 'departed', 'arrived', 'completed', 'cancelled'])], 'remarks' => 'nullable|string|max:2000']);
        if ($shipment->mode === 'Air') {
            $data['vessel'] = null;
            $data['voyage_number'] = null;
        } elseif (in_array($shipment->mode, ['Sea', 'Ocean'], true)) {
            $data['flight_number'] = null;
        }
        $booking = $shipment->bookings()->create($data);
        $this->recordEvent($shipment, 'booking_'.$data['status'], 'Forwarding', 'shipment_forwarding_booking', $booking->id, $request->user()->id, 'Forwarding booking updated.');

        return response()->json(['data' => $booking], 201);
    }

    public function container(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('forwarding.containers.manage'), 403);
        abort_unless(in_array($shipment->lcl_fcl, ['FCL', 'LCL'], true) && in_array($shipment->mode, ['Sea', 'Ocean'], true), 422, 'Container tracking is available only for sea shipments that require container handling.');
        $data = $request->validate(['booking_id' => 'nullable|integer|exists:shipment_forwarding_bookings,id', 'container_number' => ['required', 'string', 'regex:/^[A-Z]{4}[0-9]{7}$/'], 'size_type' => 'nullable|string|max:32', 'seal_number' => 'nullable|string|max:40', 'carrier' => 'nullable|string|max:180', 'status' => ['sometimes', Rule::in(['planned', 'picked_up', 'gate_in', 'loaded', 'departed', 'arrived', 'discharged', 'gate_out', 'empty_returned'])], 'remarks' => 'nullable|string|max:2000']);
        if (! empty($data['booking_id'])) {
            abort_unless($shipment->bookings()->whereKey($data['booking_id'])->exists(), 422, 'The booking must belong to this shipment.');
        }

        return response()->json(['data' => $shipment->containers()->create($data)], 201);
    }

    public function delivery(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('transport.deliveries.manage'), 403);
        $data = $request->validate(['transport_trip_id' => 'nullable|integer|exists:shipment_transport_trips,id', 'delivered_at' => 'required|date', 'delivered_to' => 'required|string|max:180', 'receiving_representative' => 'nullable|string|max:180', 'location' => 'required|string|max:180', 'quantity_delivered' => 'nullable|numeric|min:0', 'shortages_damages' => 'nullable|string|max:2000', 'recipient_acknowledged' => 'boolean', 'remarks' => 'nullable|string|max:2000']);
        if (! empty($data['transport_trip_id'])) {
            abort_unless($shipment->trips()->whereKey($data['transport_trip_id'])->exists(), 422, 'The transport leg must belong to this shipment.');
        }
        $delivery = $shipment->deliveries()->create([...$data, 'recorded_by' => $request->user()->id]);
        $this->recordEvent($shipment, 'delivery_recorded', 'Transport', 'shipment_delivery', $delivery->id, $request->user()->id, 'Delivery recorded; POD remains '.$delivery->pod_status.'.');

        return response()->json(['data' => $delivery], 201);
    }

    public function upload(Request $request, Shipment $shipment, DocumentScanService $scan)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('shipments.documents.upload') || ($request->user()->can('transport.pod.upload') && $request->input('category') === 'proof_of_delivery'), 403);
        $data = $request->validate(['file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'category' => ['required', Rule::in(['commercial_invoice', 'packing_list', 'bill_of_lading', 'air_waybill', 'arrival_notice', 'delivery_order', 'customs_entry', 'permit', 'release_document', 'proof_of_delivery', 'other'])], 'delivery_id' => 'required_if:category,proof_of_delivery|nullable|integer|exists:shipment_deliveries,id', 'expires_on' => 'nullable|date', 'remarks' => 'nullable|string|max:2000', 'upload_key' => 'nullable|string|max:120']);
        $uploadKey = $request->header('Idempotency-Key') ?? $data['upload_key'] ?? null;
        abort_if($uploadKey !== null && (trim($uploadKey) === '' || strlen($uploadKey) > 120), 422, 'The upload key must contain 1 to 120 characters.');
        if ($uploadKey && ($existing = ShipmentDocument::where('upload_key', $uploadKey)->first())) {
            abort_unless((int) $existing->shipment_id === (int) $shipment->id, 409, 'The upload key has already been used for another action.');

            return response()->json(['data' => $existing, 'idempotent_replay' => true]);
        }
        if (! empty($data['delivery_id'])) {
            abort_unless($shipment->deliveries()->whereKey($data['delivery_id'])->exists(), 422, 'The delivery record must belong to this shipment.');
        }
        $previous = $shipment->documents()->where('category', $data['category'])->latest('version')->first();
        $stored = $scan->store($request->file('file'), 'shipment-documents/'.$shipment->id);
        $document = $shipment->documents()->create(['upload_key' => $uploadKey, 'delivery_id' => $data['delivery_id'] ?? null, 'uploaded_by' => $request->user()->id, 'category' => $data['category'], 'original_name' => Str::limit(basename($request->file('file')->getClientOriginalName()), 255, ''), 'disk_path' => $stored['path'], 'mime_type' => $request->file('file')->getMimeType(), 'size_bytes' => $request->file('file')->getSize(), 'scan_status' => $stored['scan_status'], 'scan_result' => $stored['scan_result'], 'scanned_at' => $stored['scanned_at'], 'version' => ($previous?->version ?? 0) + 1, 'replaces_document_id' => $previous?->id, 'expires_on' => $data['expires_on'] ?? null, 'verification_status' => 'pending', 'remarks' => $data['remarks'] ?? null]);
        if ($data['category'] === 'proof_of_delivery') {
            $shipment->deliveries()->whereKey($data['delivery_id'])->update(['pod_status' => 'uploaded']);
        }
        $this->recordEvent($shipment, $data['category'] === 'proof_of_delivery' ? 'pod_uploaded' : 'document_uploaded', $this->userDepartment($request), 'shipment_document', $document->id, $request->user()->id, 'Private document uploaded; version '.$document->version.'.');

        return response()->json(['data' => $document, 'message' => $stored['scan_status'] === 'clean' ? 'Document scanned and stored privately.' : 'Document is quarantined until a clean scan is available.'], 201);
    }

    public function download(Request $request, ShipmentDocument $document)
    {
        abort_unless($request->user()->can('shipments.documents.view') || ($document->category === 'proof_of_delivery' && $request->user()->can('transport.pod.upload')), 403);
        abort_unless($this->visibleShipments($request)->whereKey($document->shipment_id)->exists(), 403);
        app(DocumentScanService::class)->assertTrusted($document->scan_status);
        abort_unless(Storage::disk('local')->exists($document->disk_path), 404);

        return Storage::disk('local')->download($document->disk_path, basename($document->original_name));
    }

    public function verifyDocument(Request $request, ShipmentDocument $document)
    {
        app(DocumentScanService::class)->assertTrusted($document->scan_status);
        $pod = $document->category === 'proof_of_delivery';
        abort_unless($this->visibleShipments($request)->whereKey($document->shipment_id)->exists(), 403);
        abort_unless(($pod && $request->user()->can('transport.pod.verify')) || (! $pod && $request->user()->can('operations.documents.verify')), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'rejected', 'replacement_requested'])], 'remarks' => 'required|string|min:3|max:2000']);
        $document->update(['verification_status' => $data['decision'], 'verified_by' => $request->user()->id, 'verified_at' => now(), 'remarks' => $data['remarks']]);
        if ($pod && $document->delivery_id) {
            $delivery = $document->delivery()->first();
            $delivery?->update(['pod_status' => $data['decision']]);
        }
        $this->recordEvent($document->shipment, $pod ? 'pod_'.$data['decision'] : 'document_'.$data['decision'], $pod ? 'Transport' : 'Operations', 'shipment_document', $document->id, $request->user()->id, $data['remarks']);

        return response()->json(['data' => $document->refresh()]);
    }

    public function raiseException(Request $request, Shipment $shipment)
    {
        $this->assertShipmentVisible($request, $shipment);
        abort_unless($request->user()->can('shipment.exceptions.manage'), 403);
        $data = $request->validate(['department' => ['nullable', Rule::in(self::DEPARTMENTS)], 'severity' => ['required', Rule::in(['low', 'normal', 'high', 'critical'])], 'title' => 'required|string|max:180', 'description' => 'required|string|max:4000']);
        $exception = $shipment->exceptions()->create([...$data, 'reported_by' => $request->user()->id]);
        $this->recordEvent($shipment, 'exception_raised', $data['department'] ?? $this->userDepartment($request), 'shipment_exception', $exception->id, $request->user()->id, $data['title'].': '.$data['description']);

        return response()->json(['data' => $exception], 201);
    }

    public function resolveException(Request $request, ShipmentException $exception)
    {
        abort_unless($this->visibleShipments($request)->whereKey($exception->shipment_id)->exists(), 403);
        abort_unless($request->user()->can('shipment.exceptions.manage'), 403);
        $data = $request->validate(['resolution' => 'required|string|min:3|max:2000']);
        abort_unless($exception->status === 'open', 409);
        $exception->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        $this->recordEvent($exception->shipment, 'exception_resolved', $exception->department, 'shipment_exception', $exception->id, $request->user()->id, $data['resolution']);

        return response()->json(['data' => $exception->refresh()]);
    }

    public function history(Request $request, Shipment $shipment)
    {
        abort_unless($request->user()->can('shipments.timeline.view') && $this->visibleShipments($request)->whereKey($shipment->id)->exists(), 403);

        return response()->json(['data' => $shipment->events()->with('actor:id,name')->orderBy('occurred_at')->orderBy('id')->get(), 'status_history' => $shipment->statusHistory()->with('actor:id,name')->orderBy('created_at')->get()]);
    }

    public function reconciliation(Request $request)
    {
        abort_unless($request->user()->can('shipment.reconciliation.view'), 403);
        $base = $this->visibleShipments($request)->whereNull('archived_at');
        $counts = [];
        foreach (self::DEPARTMENTS as $department) {
            $counts[$department] = (clone $base)->whereHas('assignments', fn ($q) => $q->where('department', $department))->distinct('shipments.id')->count('shipments.id');
        }
        $counts['master_jobs'] = (clone $base)->count();

        return response()->json(['data' => $counts, 'explanation' => 'Department counts are distinct master job IDs with an assignment to that department; they are not trip, container, or delivery counts.']);
    }

    private function financialReadiness(Shipment $shipment): array
    {
        if (! $shipment->commercial_baseline_id) {
            return ['commercial_baseline' => 'pending', 'funding_status' => 'not_authorized', 'estimated_requirement_by_currency' => [], 'allocated_by_currency' => [], 'note' => 'No accepted client baseline is linked. Direct-origin jobs require commercial and funding review.'];
        }
        if (! $shipment->forecast_id || ! $shipment->inquiry_id) {
            return ['commercial_baseline' => 'accepted', 'funding_status' => 'forecast_not_linked', 'estimated_requirement_by_currency' => [], 'allocated_by_currency' => [], 'note' => 'An accepted commercial baseline exists, but no shipment forecast is linked for budget requirement matching.'];
        }

        $forecast = DB::table('shipment_forecasts')->where('id', $shipment->forecast_id)->first();
        $estimate = DB::table('cost_estimates')->where('inquiry_id', $shipment->inquiry_id)->where('status', 'approved')->where(function ($query) use ($shipment): void {
            $query->whereNull('forecast_id')->orWhere('forecast_id', $shipment->forecast_id);
        })->orderByDesc('version')->first();
        if (! $forecast || ! $estimate) {
            return ['commercial_baseline' => 'accepted', 'funding_status' => 'estimate_not_approved', 'forecast_confidence' => $forecast->confidence ?? null, 'estimated_requirement_by_currency' => [], 'allocated_by_currency' => [], 'note' => 'No approved cost estimate is linked to this forecast; no funding requirement amount is inferred.'];
        }

        $requirements = [];
        $allocations = [];
        foreach (DB::table('cost_estimate_lines')->where('cost_estimate_id', $estimate->id)->where('funding_required', true)->get() as $line) {
            $amount = BigDecimal::of((string) $line->estimate_currency_total)->multipliedBy((int) $forecast->expected_shipment_count)->toScale(2, RoundingMode::HalfUp);
            $requirements[$estimate->currency] = ($requirements[$estimate->currency] ?? BigDecimal::zero())->plus($amount);
            $source = $estimate->reference.'-v'.$estimate->version.'-L'.$line->id;
            foreach (DB::table('budget_allocations')->where('requirement_source', $source)->get(['amount']) as $allocation) {
                $allocations[$estimate->currency] = ($allocations[$estimate->currency] ?? BigDecimal::zero())->plus((string) $allocation->amount);
            }
        }
        $required = $requirements[$estimate->currency] ?? BigDecimal::zero();
        $allocated = $allocations[$estimate->currency] ?? BigDecimal::zero();

        return [
            'commercial_baseline' => 'accepted', 'funding_status' => $allocated->isZero() ? 'approved_requirement_unallocated' : 'partially_or_fully_allocated',
            'forecast_confidence' => $forecast->confidence, 'forecast_month' => substr((string) $forecast->forecast_month, 0, 7),
            'estimate_reference' => $estimate->reference.'-v'.$estimate->version,
            'estimated_requirement_by_currency' => [['currency' => $estimate->currency, 'amount' => (string) $required->toScale(2, RoundingMode::HalfUp)]],
            'allocated_by_currency' => [['currency' => $estimate->currency, 'amount' => (string) $allocated->toScale(2, RoundingMode::HalfUp)]],
            'unallocated_requirement_by_currency' => [['currency' => $estimate->currency, 'amount' => (string) ($required->minus($allocated)->isNegative() ? BigDecimal::zero() : $required->minus($allocated))->toScale(2, RoundingMode::HalfUp)]],
            'note' => 'Estimate demand is not an actual expense or payment. Currency values are not converted.',
        ];
    }

    private function visibleShipments(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->can('shipments.view'), 403);
        if ($user->hasAnyRole(['Administrator', 'Executive'])) {
            return Shipment::query();
        }
        $roles = $user->getRoleNames()->all();
        if (array_intersect(['Accounting', 'Finance'], $roles)) {
            return Shipment::query();
        }
        $departments = array_values(array_intersect(self::DEPARTMENTS, $roles));
        if (in_array('Sales', $roles, true)) {
            return Shipment::query()->where(fn ($q) => $q->where('created_by', $user->id)->orWhereHas('inquiry', fn ($i) => $i->where('sales_owner_id', $user->id))->orWhereHas('quotation', fn ($quote) => $quote->where('sales_owner_id', $user->id)));
        }

        return Shipment::query()->whereHas('assignments', fn ($q) => $q->whereIn('department', $departments)->where(function ($assigned) use ($user): void {
            $assigned->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id);
        }));
    }

    private function userDepartment(Request $request): ?string
    {
        $roles = $request->user()->getRoleNames()->all();
        foreach (self::DEPARTMENTS as $department) {
            if (in_array($department, $roles, true)) {
                return $department;
            }
        }

        return null;
    }

    private function assertShipmentVisible(Request $request, Shipment $shipment): void
    {
        abort_unless($this->visibleShipments($request)->whereKey($shipment->id)->exists(), 403);
    }

    private function authorizeDepartmentMutation(Request $request, string $department): void
    {
        $permissions = ['Operations' => 'operations.jobs.manage', 'Transport' => 'transport.dispatch.manage', 'Forwarding' => 'forwarding.milestones.manage'];
        abort_unless($request->user()->hasRole($department) && $request->user()->can($permissions[$department]), 403);
    }

    private function departmentStatus(Shipment $shipment, string $department, string $status): void
    {
        $assignment = $shipment->assignments()->where('department', $department)->first();
        if ($assignment) {
            $assignment->update(['status' => $status === 'completed' ? 'completed' : ($status === 'in_progress' ? 'active' : $assignment->status)]);
        }
    }

    private function assertNoScheduleConflict(array $data, ?int $exceptTripId = null): void
    {
        if (empty($data['driver_id']) && empty($data['plate_number'])) {
            return;
        }
        $query = ShipmentTransportTrip::query()->when($exceptTripId, fn ($q) => $q->where('id', '!=', $exceptTripId))->whereNotIn('status', ['cancelled', 'delivered', 'pod_verified'])->where(function ($q) use ($data): void {
            if (! empty($data['driver_id'])) {
                $q->orWhere('driver_id', $data['driver_id']);
            }
            if (! empty($data['plate_number'])) {
                $q->orWhere('plate_number', strtoupper($data['plate_number']));
            }
        });
        if (! empty($data['scheduled_pickup']) && ! empty($data['scheduled_delivery'])) {
            $query->where(fn ($q) => $q->whereBetween('scheduled_pickup', [$data['scheduled_pickup'], $data['scheduled_delivery']])->orWhereBetween('scheduled_delivery', [$data['scheduled_pickup'], $data['scheduled_delivery']])->orWhere(fn ($overlap) => $overlap->where('scheduled_pickup', '<=', $data['scheduled_pickup'])->where('scheduled_delivery', '>=', $data['scheduled_delivery'])));
        }
        abort_if($query->exists(), 409, 'The selected driver or vehicle already has an overlapping scheduled transport leg.');
    }

    private function recordEvent(Shipment $shipment, string $type, ?string $department, ?string $sourceType, ?int $sourceId, ?int $actorId, string $notes, ?string $key = null): void
    {
        if ($key && ShipmentTimelineEvent::where('idempotency_key', $key)->exists()) {
            return;
        }
        ShipmentTimelineEvent::create(['shipment_id' => $shipment->id, 'event_type' => $type, 'department' => $department, 'actor_id' => $actorId, 'source_type' => $sourceType, 'source_id' => $sourceId, 'idempotency_key' => $key, 'occurred_at' => now(), 'notes' => $notes]);
    }
}
