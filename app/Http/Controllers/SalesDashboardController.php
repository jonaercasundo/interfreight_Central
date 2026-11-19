<?php

namespace App\Http\Controllers;

use App\Models\ClientInquiry;
use App\Models\Quotation;
use App\Models\SalesCapacityAvailability;
use App\Models\SalesCapacityEvent;
use App\Models\Shipment;
use App\Models\ShipmentForecast;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesDashboardController extends Controller
{
    public function page(string $page = 'sales-dashboard')
    {
        return view('app', ['page' => $page]);
    }

    public function summary(Request $request)
    {
        $ownerId = $request->user()->id;
        $ownedInquiries = ClientInquiry::query()->where('sales_owner_id', $ownerId);
        $availability = $this->verifiedAvailabilityQuery();
        $units = (clone $availability)->get()->groupBy('capacity_unit')->map(fn (Collection $records): float => round($records->sum(fn ($record): float => max(0, (float) $record->confirmed_capacity - (float) $record->reserved_capacity)), 3));

        return response()->json(['data' => [
            'total_inquiries' => (clone $ownedInquiries)->count(),
            'draft_inquiries' => (clone $ownedInquiries)->where('status', 'draft')->count(),
            'submitted_inquiries' => (clone $ownedInquiries)->where('status', 'submitted')->count(),
            'under_review_inquiries' => (clone $ownedInquiries)->where('status', 'under_review')->count(),
            'forecasted_inquiries' => (clone $ownedInquiries)->where('status', 'forecasted')->count(),
            'forecast_shipments' => ShipmentForecast::query()->where('sales_owner_id', $ownerId)->where('status', 'active')->sum('expected_shipment_count'),
            'approved_quotations' => Quotation::query()->where('sales_owner_id', $ownerId)->whereIn('status', ['approved', 'issued', 'client_accepted'])->count(),
            'upcoming_shipments' => $this->ownedShipments($request)->whereNotNull('planned_departure')->whereBetween('planned_departure', [today(), today()->addDays(90)])->whereNotIn('master_status', ['cancelled', 'closed'])->count(),
            'available_capacity' => [
                'confirmed_records' => (clone $availability)->count(),
                'by_unit' => $units,
                'message' => $units->isEmpty() ? 'Availability not yet confirmed' : null,
            ],
        ]]);
    }

    public function calendar(Request $request)
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'route' => ['nullable', 'string', 'max:180'],
            'mode' => ['nullable', Rule::in(['Sea', 'Air', 'Road', 'Rail'])],
            'status' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = CarbonImmutable::parse($filters['from'])->startOfDay();
        $to = CarbonImmutable::parse($filters['to'])->endOfDay();
        if ($from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['to' => 'Calendar ranges may not exceed 93 days.']);
        }

        $events = collect();
        $forecasts = ShipmentForecast::query()->with(['inquiry.client:id,company_name', 'inquiry:id,client_id,reference,origin,destination,transport_mode,service_type,cargo_description,requested_ship_date,sales_owner_id'])
            ->where('sales_owner_id', $request->user()->id)->where('status', 'active')
            ->where(function (Builder $query) use ($from, $to): void {
                $query->whereBetween('forecast_month', [$from->startOfMonth()->toDateString(), $to->startOfMonth()->toDateString()])
                    ->orWhereHas('inquiry', fn (Builder $inquiry) => $inquiry->whereBetween('requested_ship_date', [$from->toDateString(), $to->toDateString()]));
            })
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $clientId) => $query->whereHas('inquiry', fn (Builder $inquiry) => $inquiry->where('client_id', $clientId)))
            ->when($filters['mode'] ?? null, fn (Builder $query, string $mode) => $query->whereHas('inquiry', fn (Builder $inquiry) => $inquiry->where('transport_mode', $mode)))
            ->when($filters['route'] ?? null, fn (Builder $query, string $route) => $query->whereHas('inquiry', fn (Builder $inquiry) => $this->routeSearch($inquiry, $route)))
            ->get();

        foreach ($forecasts as $forecast) {
            $inquiry = $forecast->inquiry;
            $target = $inquiry->requested_ship_date;
            $forecastDate = $target && $target->betweenIncluded($from, $to) ? $target : $forecast->forecast_month;
            if ($forecastDate->lt($from) || $forecastDate->gt($to)) {
                continue;
            }
            $events->push([
                'key' => 'forecast-'.$forecast->id,
                'kind' => 'forecast',
                'source_type' => 'forecast',
                'source_id' => $forecast->id,
                'detail_type' => 'inquiry',
                'detail_id' => $inquiry->id,
                'title' => 'Forecast · '.$inquiry->reference,
                'date' => $forecastDate->toDateString(),
                'date_precision' => $target ? 'day' : 'month',
                'client' => $inquiry->client?->company_name ?? 'Client not recorded',
                'route' => $inquiry->origin.' → '.$inquiry->destination,
                'transport_mode' => $inquiry->transport_mode,
                'service_type' => $inquiry->service_type,
                'status' => $forecast->confidence,
                'label' => 'Forecast only',
                'details' => ['inquiry_reference' => $inquiry->reference, 'expected_shipment_count' => $forecast->expected_shipment_count, 'forecast_month' => $forecast->forecast_month->format('Y-m'), 'cargo_description' => $inquiry->cargo_description],
            ]);
        }

        $shipments = $this->ownedShipments($request)->with([
            'client:id,company_name', 'inquiry:id,reference,origin,destination,transport_mode,service_type,cargo_description,requested_ship_date,sales_owner_id',
            'bookings', 'trips', 'milestones',
        ])->whereNotIn('master_status', ['cancelled', 'closed'])
            ->where(function (Builder $query) use ($from, $to): void {
                $query->whereBetween('planned_departure', [$from->toDateString(), $to->toDateString()])
                    ->orWhereHas('bookings', fn (Builder $booking) => $booking->whereIn('status', ['confirmed', 'departed', 'arrived', 'completed'])->where(fn (Builder $q) => $q->whereBetween('etd', [$from, $to])->orWhereBetween('eta', [$from, $to])))
                    ->orWhereHas('trips', fn (Builder $trip) => $trip->where(fn (Builder $q) => $q->whereBetween('scheduled_pickup', [$from, $to])->orWhereBetween('scheduled_delivery', [$from, $to])))
                    ->orWhereHas('milestones', fn (Builder $milestone) => $milestone->whereBetween('planned_at', [$from, $to]));
            })
            ->when($filters['client_id'] ?? null, fn (Builder $query, int $clientId) => $query->where('client_id', $clientId))
            ->when($filters['mode'] ?? null, fn (Builder $query, string $mode) => $query->where('mode', $mode))
            ->when($filters['route'] ?? null, fn (Builder $query, string $route) => $query->where(fn (Builder $q) => $q->where('origin', 'like', '%'.$route.'%')->orWhere('destination', 'like', '%'.$route.'%')))
            ->get();

        foreach ($shipments as $shipment) {
            $base = [
                'source_type' => 'shipment', 'source_id' => $shipment->id, 'detail_type' => 'shipment', 'detail_id' => $shipment->id,
                'client' => $shipment->client?->company_name ?? 'Client not recorded', 'route' => $shipment->origin.' → '.$shipment->destination,
                'transport_mode' => $shipment->mode, 'service_type' => $shipment->service_type ?? $shipment->lcl_fcl,
                'status' => $shipment->master_status, 'details' => ['job_number' => $shipment->job_number, 'master_status' => $shipment->master_status, 'cargo' => $shipment->cargo],
            ];
            $add = function ($key, $title, $date, $kind, $status = null, $extra = []) use ($events, $base, $from, $to): void {
                if (! $date) {
                    return;
                }
                $date = CarbonImmutable::parse($date);
                if ($date->lt($from) || $date->gt($to)) {
                    return;
                }
                $events->push([...$base, 'key' => $key, 'title' => $title, 'date' => $date->toDateString(), 'date_precision' => 'day', 'kind' => $kind, 'label' => 'Confirmed schedule', 'status' => $status ?? $base['status'], 'details' => [...$base['details'], ...$extra]]);
            };

            $add('shipment-'.$shipment->id.'-departure', 'Shipment departure · '.$shipment->job_number, $shipment->planned_departure, 'shipment');
            foreach ($shipment->bookings as $booking) {
                if (in_array($booking->status, ['confirmed', 'departed', 'arrived', 'completed'], true)) {
                    $add('booking-'.$booking->id.'-etd', 'Booking departure · '.$shipment->job_number, $booking->etd, 'booking', $booking->status, ['booking_reference' => $booking->booking_reference, 'carrier' => $booking->carrier, 'vessel' => $booking->vessel, 'flight_number' => $booking->flight_number]);
                    $add('booking-'.$booking->id.'-eta', 'Booking arrival · '.$shipment->job_number, $booking->eta, 'booking', $booking->status, ['booking_reference' => $booking->booking_reference, 'carrier' => $booking->carrier]);
                }
            }
            foreach ($shipment->trips as $trip) {
                $add('trip-'.$trip->id.'-pickup', 'Transport pickup · '.$shipment->job_number, $trip->scheduled_pickup, 'transport', $trip->status, ['leg_reference' => $trip->leg_reference, 'provider' => $trip->provider]);
                $add('trip-'.$trip->id.'-delivery', 'Transport delivery · '.$shipment->job_number, $trip->scheduled_delivery, 'transport', $trip->status, ['leg_reference' => $trip->leg_reference, 'provider' => $trip->provider]);
            }
            foreach ($shipment->milestones as $milestone) {
                $add('milestone-'.$milestone->id, $milestone->label.' · '.$shipment->job_number, $milestone->planned_at, 'milestone', $milestone->status, ['department' => $milestone->department, 'milestone_status' => $milestone->status]);
            }
        }

        $events = $events->when($filters['status'] ?? null, fn (Collection $collection, string $status) => $collection->where('status', $status))
            ->sortBy([['date', 'asc'], ['title', 'asc']])->values();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 50)));
        $paginator = new LengthAwarePaginator($events->forPage($page, $perPage)->values(), $events->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return response()->json([
            'data' => $paginator,
            'clients' => DB::table('clients')->where('sales_owner_id', $request->user()->id)->orderBy('company_name')->get(['id', 'company_name']),
        ]);
    }

    public function calendarDetail(Request $request, string $type, int $id)
    {
        abort_unless($request->user()->can('sales.calendar.view'), 403);
        if ($type === 'inquiry') {
            $record = ClientInquiry::query()->with('client:id,company_name,client_type')->where('sales_owner_id', $request->user()->id)->findOrFail($id);

            return response()->json(['data' => ['type' => 'inquiry', 'record' => $record]]);
        }
        abort_unless($type === 'shipment', 404);
        $shipment = $this->ownedShipments($request)->with(['client:id,company_name', 'bookings', 'trips', 'milestones'])->findOrFail($id);

        return response()->json(['data' => ['type' => 'shipment', 'record' => $shipment]]);
    }

    public function availability(Request $request)
    {
        abort_unless($request->user()->can('sales.availability.view'), 403);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'route' => ['nullable', 'string', 'max:180'],
            'mode' => ['nullable', Rule::in(['Sea', 'Air', 'Road', 'Rail'])],
            'status' => ['nullable', Rule::in(['available', 'fully_reserved', 'expired', 'deactivated'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SalesCapacityAvailability::query()->with(['verifier:id,name'])
            ->when($filters['mode'] ?? null, fn (Builder $q, string $mode) => $q->where('transport_mode', $mode))
            ->when($filters['route'] ?? null, fn (Builder $q, string $route) => $q->where(fn (Builder $nested) => $nested->where('origin', 'like', '%'.$route.'%')->orWhere('destination', 'like', '%'.$route.'%')))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $nested) => $nested->where('reference', 'like', '%'.$search.'%')->orWhere('source_reference', 'like', '%'.$search.'%')->orWhere('origin', 'like', '%'.$search.'%')->orWhere('destination', 'like', '%'.$search.'%')));
        if (! $request->user()->can('sales.availability.manage')) {
            $query->whereNull('deactivated_at')->where('verified_at', '<=', now())->where('valid_from', '<=', today())->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
        }
        if (isset($filters['status'])) {
            $query->when($filters['status'] === 'deactivated', fn (Builder $q) => $q->whereNotNull('deactivated_at'))
                ->when($filters['status'] === 'expired', fn (Builder $q) => $q->whereNull('deactivated_at')->where(fn (Builder $nested) => $nested->whereDate('departure_date', '<', today())->orWhereDate('valid_until', '<', today())))
                ->when($filters['status'] === 'fully_reserved', fn (Builder $q) => $q->whereNull('deactivated_at')->where('confirmed_capacity', '<=', DB::raw('reserved_capacity'))->whereDate('departure_date', '>=', today()))
                ->when($filters['status'] === 'available', fn (Builder $q) => $q->whereNull('deactivated_at')->whereColumn('confirmed_capacity', '>', 'reserved_capacity')->whereDate('departure_date', '>=', today())->where('valid_from', '<=', today())->where(fn (Builder $nested) => $nested->whereNull('valid_until')->orWhereDate('valid_until', '>=', today())));
        }

        $records = $query->orderBy('departure_date')->paginate($filters['per_page'] ?? 20);
        $records->setCollection($records->getCollection()->map(fn (SalesCapacityAvailability $record): array => $this->availabilityPayload($record)));

        return response()->json(['data' => $records]);
    }

    public function storeAvailability(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('sales.availability.manage'), 403);
        $data = $this->validatedAvailability($request);
        $record = DB::transaction(function () use ($data, $request): SalesCapacityAvailability {
            $record = SalesCapacityAvailability::create([...$data, 'reference' => 'CAP-'.Str::upper((string) Str::ulid()), 'reserved_capacity' => '0.000', 'created_by' => $request->user()->id, 'verified_by' => $request->user()->id, 'verified_at' => now(), 'lock_version' => 1]);
            $this->recordCapacityEvent($record, $request, 'created', null, $record->only(['origin', 'destination', 'transport_mode', 'service_type', 'departure_date', 'confirmed_capacity', 'capacity_unit', 'source_type', 'source_reference', 'valid_from', 'valid_until', 'booking_cutoff']));

            return $record->refresh()->load('verifier:id,name');
        });
        $audit->record('sales.availability.created', $record, ['reference' => $record->reference, 'source_type' => $record->source_type, 'capacity_unit' => $record->capacity_unit]);

        return response()->json(['data' => $this->availabilityPayload($record)], 201);
    }

    public function updateAvailability(Request $request, SalesCapacityAvailability $availability, AuditLogger $audit)
    {
        abort_unless($request->user()->can('sales.availability.manage'), 403);
        $data = $this->validatedAvailability($request, false);
        $data['lock_version'] = $request->validate(['lock_version' => ['required', 'integer', 'min:1']])['lock_version'];
        $record = DB::transaction(function () use ($availability, $data, $request): SalesCapacityAvailability {
            $locked = SalesCapacityAvailability::query()->lockForUpdate()->findOrFail($availability->id);
            if ($locked->lock_version !== (int) $data['lock_version']) {
                abort(409, 'This availability record changed. Reload it before saving.');
            }
            abort_if($locked->deactivated_at, 409, 'A deactivated availability record cannot be changed.');
            $before = $locked->only(['origin', 'destination', 'transport_mode', 'service_type', 'departure_date', 'confirmed_capacity', 'reserved_capacity', 'capacity_unit', 'booking_cutoff', 'source_type', 'source_reference', 'verified_by', 'verified_at', 'valid_from', 'valid_until', 'deactivated_at']);
            $confirmed = (float) ($data['confirmed_capacity'] ?? $locked->confirmed_capacity);
            $reserved = (float) ($data['reserved_capacity'] ?? $locked->reserved_capacity);
            if ($reserved > $confirmed) {
                throw ValidationException::withMessages(['confirmed_capacity' => 'Confirmed capacity cannot be lower than reserved capacity.']);
            }
            $locked->fill([...$data, 'verified_by' => $request->user()->id, 'verified_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
            $after = $locked->only(array_keys($before));
            $this->recordCapacityEvent($locked, $request, 'updated', $before, $after);

            return $locked->refresh()->load('verifier:id,name');
        });
        $audit->record('sales.availability.updated', $record, ['reference' => $record->reference, 'lock_version' => $record->lock_version]);

        return response()->json(['data' => $this->availabilityPayload($record)]);
    }

    public function deactivateAvailability(Request $request, SalesCapacityAvailability $availability, AuditLogger $audit)
    {
        abort_unless($request->user()->can('sales.availability.manage'), 403);
        $version = $request->validate(['lock_version' => ['required', 'integer', 'min:1']])['lock_version'];
        $record = DB::transaction(function () use ($availability, $request, $version): SalesCapacityAvailability {
            $locked = SalesCapacityAvailability::query()->lockForUpdate()->findOrFail($availability->id);
            if ($locked->lock_version !== (int) $version) {
                abort(409, 'This availability record changed. Reload it before deactivating.');
            }
            if (! $locked->deactivated_at) {
                $before = $locked->only(['deactivated_at', 'lock_version']);
                $locked->update(['deactivated_at' => now(), 'lock_version' => $locked->lock_version + 1]);
                $this->recordCapacityEvent($locked, $request, 'deactivated', $before, $locked->only(['deactivated_at', 'lock_version']));
            }

            return $locked->refresh()->load('verifier:id,name');
        });
        $audit->record('sales.availability.deactivated', $record, ['reference' => $record->reference]);

        return response()->json(['data' => $this->availabilityPayload($record)]);
    }

    private function ownedShipments(Request $request): Builder
    {
        return Shipment::query()->where(function (Builder $query) use ($request): void {
            $query->whereHas('inquiry', fn (Builder $inquiry) => $inquiry->where('sales_owner_id', $request->user()->id))
                ->orWhere('created_by', $request->user()->id);
        })->whereNull('archived_at');
    }

    private function verifiedAvailabilityQuery(): Builder
    {
        return SalesCapacityAvailability::query()->whereNull('deactivated_at')->where('verified_at', '<=', now())
            ->where('valid_from', '<=', today())->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->whereDate('departure_date', '>=', today());
    }

    private function routeSearch(Builder $query, string $route): Builder
    {
        return $query->where(fn (Builder $nested) => $nested->where('origin', 'like', '%'.$route.'%')->orWhere('destination', 'like', '%'.$route.'%'));
    }

    private function validatedAvailability(Request $request, bool $required = true): array
    {
        $presence = $required ? ['required'] : ['sometimes'];

        return $request->validate([
            'origin' => [...$presence, 'string', 'max:180'], 'destination' => [...$presence, 'string', 'max:180'],
            'transport_mode' => [...$presence, Rule::in(['Sea', 'Air', 'Road', 'Rail'])],
            'service_type' => [...$presence, Rule::in(['LCL', 'FCL'])],
            'departure_date' => [...$presence, 'date_format:Y-m-d', 'after_or_equal:today'],
            'confirmed_capacity' => [...$presence, 'numeric', 'gt:0', 'max:999999999999.999'],
            'reserved_capacity' => ['sometimes', 'numeric', 'min:0', 'max:999999999999.999'],
            'capacity_unit' => [...$presence, Rule::in(['CBM', 'TEU', 'kg', 'truck_slots', 'pallets'])],
            'booking_cutoff' => ['nullable', 'date'],
            'source_type' => [...$presence, Rule::in(['carrier_verified', 'internal_allocation'])],
            'source_reference' => [...$presence, 'string', 'max:180'],
            'valid_from' => [...$presence, 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ]);
    }

    private function availabilityPayload(SalesCapacityAvailability $record): array
    {
        return [...$record->toArray(), 'available_capacity' => $record->available_capacity, 'availability_status' => $record->availability_status];
    }

    private function recordCapacityEvent(SalesCapacityAvailability $record, Request $request, string $type, ?array $before, ?array $after): void
    {
        SalesCapacityEvent::create(['availability_id' => $record->id, 'actor_id' => $request->user()->id, 'event_type' => $type, 'before_values' => $before, 'after_values' => $after, 'created_at' => now()]);
    }
}
