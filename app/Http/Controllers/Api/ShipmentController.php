<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentStatusHistory;
use App\Models\ShipmentTimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        return $this->visibleShipments($request)->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('reference', 'like', "%$s%")->orWhere('job_number', 'like', "%$s%")->orWhere('customer_name', 'like', "%$s%")->orWhere('origin', 'like', "%$s%")->orWhere('destination', 'like', "%$s%")))->when($request->status, fn ($q, $s) => $q->where('status', $s))->latest()->paginate(12);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('shipments.manage'), 403);
        $data = $request->validate(['customer_name' => 'required|string|max:150', 'origin' => 'required|string|max:150', 'destination' => 'required|string|max:150', 'mode' => ['required', Rule::in(['Air', 'Ocean', 'Road', 'Rail'])], 'status' => ['sometimes', Rule::in(['Booked', 'In transit', 'Customs', 'Delivered', 'Delayed'])], 'cargo' => 'required|string|max:200', 'weight_kg' => 'required|numeric|min:0', 'estimated_delivery' => 'nullable|date', 'freight_cost' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:2000']);
        $shipment = DB::transaction(function () use ($request, $data): Shipment {
            $year = now()->year;
            DB::table('shipment_job_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = DB::table('shipment_job_sequences')->where('year', $year)->lockForUpdate()->first();
            $next = $sequence->last_value + 1;
            DB::table('shipment_job_sequences')->where('year', $year)->update(['last_value' => $next, 'updated_at' => now()]);
            $jobNumber = sprintf('IFC-%d-%06d', $year, $next);
            $legacyStatus = $data['status'] ?? 'Booked';
            $masterStatus = match ($legacyStatus) {
                'Delivered' => 'operationally_completed',
                'In transit', 'Customs', 'Delayed' => 'in_progress',
                default => 'open',
            };
            $shipment = Shipment::create([...$data, 'job_number' => $jobNumber, 'reference' => $jobNumber, 'created_by' => $request->user()->id, 'source_type' => 'legacy', 'master_status' => $masterStatus]);
            ShipmentStatusHistory::create(['shipment_id' => $shipment->id, 'actor_id' => $request->user()->id, 'scope' => 'master', 'to_status' => $masterStatus, 'reason' => 'Registered through the compatibility API.']);
            ShipmentTimelineEvent::create(['shipment_id' => $shipment->id, 'event_type' => 'job_created', 'actor_id' => $request->user()->id, 'source_type' => 'shipment', 'source_id' => $shipment->id, 'occurred_at' => now(), 'notes' => 'Legacy API shipment registered in the master register.']);

            return $shipment;
        });

        return response()->json($shipment, 201);
    }

    public function show(Shipment $shipment)
    {
        abort_unless($this->visibleShipments(request())->whereKey($shipment->id)->exists(), 403);

        return $shipment;
    }

    public function update(Request $request, Shipment $shipment)
    {
        abort_unless($this->visibleShipments($request)->whereKey($shipment->id)->exists(), 403);
        abort_unless($request->user()->can('shipments.manage'), 403);
        $data = $request->validate(['customer_name' => 'sometimes|required|string|max:150', 'origin' => 'sometimes|required|string|max:150', 'destination' => 'sometimes|required|string|max:150', 'mode' => ['sometimes', Rule::in(['Air', 'Ocean', 'Road', 'Rail'])], 'status' => ['sometimes', Rule::in(['Booked', 'In transit', 'Customs', 'Delivered', 'Delayed'])], 'cargo' => 'sometimes|required|string|max:200', 'weight_kg' => 'sometimes|required|numeric|min:0', 'estimated_delivery' => 'nullable|date', 'freight_cost' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:2000']);
        $before = $shipment->master_status;
        $masterStatus = isset($data['status']) ? match ($data['status']) {
            'Delivered' => 'operationally_completed',
            'In transit', 'Customs', 'Delayed' => 'in_progress',
            default => 'open',
        } : $before;
        $shipment->update([...$data, 'master_status' => $masterStatus, 'lock_version' => $shipment->lock_version + 1]);
        if ($before !== $masterStatus) {
            ShipmentStatusHistory::create(['shipment_id' => $shipment->id, 'actor_id' => $request->user()->id, 'scope' => 'master', 'from_status' => $before, 'to_status' => $masterStatus, 'reason' => 'Compatibility API status update.']);
            ShipmentTimelineEvent::create(['shipment_id' => $shipment->id, 'event_type' => 'master_status_changed', 'actor_id' => $request->user()->id, 'source_type' => 'shipment', 'source_id' => $shipment->id, 'occurred_at' => now(), 'notes' => 'Master status updated through the compatibility API.']);
        }

        return $shipment->refresh();
    }

    public function destroy(Request $request, Shipment $shipment)
    {
        abort_unless($this->visibleShipments($request)->whereKey($shipment->id)->exists(), 403);
        abort_unless($request->user()->can('shipments.manage'), 403);
        $shipment->update(['archived_at' => now(), 'lock_version' => $shipment->lock_version + 1]);
        ShipmentTimelineEvent::create(['shipment_id' => $shipment->id, 'event_type' => 'shipment_archived', 'actor_id' => $request->user()->id, 'source_type' => 'shipment', 'source_id' => $shipment->id, 'occurred_at' => now(), 'notes' => 'Shipment archived through the compatibility API.']);

        return response()->noContent();
    }

    private function visibleShipments(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->can('shipments.view'), 403);
        if ($user->hasAnyRole(['Administrator', 'Executive', 'Accounting', 'Finance'])) {
            return Shipment::query();
        }
        if ($user->hasRole('Sales')) {
            return Shipment::query()->where(fn ($q) => $q->where('created_by', $user->id)->orWhereHas('inquiry', fn ($i) => $i->where('sales_owner_id', $user->id))->orWhereHas('quotation', fn ($quote) => $quote->where('sales_owner_id', $user->id)));
        }
        $departments = array_values(array_intersect(['Operations', 'Transport', 'Forwarding'], $user->getRoleNames()->all()));

        return Shipment::query()->whereHas('assignments', fn ($q) => $q->whereIn('department', $departments)->where(fn ($assigned) => $assigned->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id)));
    }
}
