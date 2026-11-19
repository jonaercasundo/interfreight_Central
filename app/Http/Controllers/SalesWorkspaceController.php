<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ForecastRevision;
use App\Models\ShipmentForecast;
use App\Services\AuditLogger;
use App\Services\FreightLocationCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesWorkspaceController extends Controller
{
    public function page(string $page = 'sales')
    {
        return view('app', ['page' => $page]);
    }

    public function clients(Request $request)
    {
        abort_unless($request->user()->can('clients.view'), 403);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'client_type' => ['nullable', Rule::in(['LCL', 'FCL', 'Mixed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return Client::query()->with('owner:id,name')->withCount('inquiries')->where('sales_owner_id', $request->user()->id)
            ->when($filters['search'] ?? null, function (Builder $query, $search): void {
                $term = '%'.$search.'%';
                $query->where(fn (Builder $q) => $q->where('company_name', 'like', $term)->orWhere('reference', 'like', $term));
            })
            ->when($filters['client_type'] ?? null, fn (Builder $query, $type) => $query->where('client_type', $type))
            ->orderBy('company_name')->paginate(15);
    }

    public function showClient(Request $request, Client $client)
    {
        abort_unless($request->user()->can('clients.view'), 403);
        abort_unless($client->sales_owner_id === $request->user()->id, 404);

        return response()->json(['data' => $client->load('owner:id,name')->loadCount('inquiries')]);
    }

    public function summary(Request $request)
    {
        abort_unless($request->user()->can('department.sales.view'), 403);
        $ownerId = $request->user()->id;

        return response()->json(['data' => [
            'clients' => Client::where('sales_owner_id', $ownerId)->count(),
            'open_inquiries' => ClientInquiry::where('sales_owner_id', $ownerId)->whereIn('status', ['draft', 'submitted', 'under_review', 'forecasted'])->count(),
            'expected_shipments' => ShipmentForecast::where('sales_owner_id', $ownerId)->where('status', 'active')->sum('expected_shipment_count'),
            'forecast_months' => ShipmentForecast::where('sales_owner_id', $ownerId)->where('status', 'active')->distinct()->count('forecast_month'),
        ]]);
    }

    public function storeClient(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('clients.create'), 403);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:180'], 'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'string', 'max:2000'], 'client_type' => ['required', Rule::in(['LCL', 'FCL', 'Mixed'])],
        ]);
        $client = DB::transaction(function () use ($data, $request): Client {
            $client = Client::create([...$data, 'sales_owner_id' => $request->user()->id]);
            $client->forceFill(['reference' => 'CLI-'.now()->format('Y').'-'.str_pad((string) $client->id, 6, '0', STR_PAD_LEFT)])->save();

            return $client->refresh();
        });
        $audit->record('clients.created', $client, ['reference' => $client->reference]);

        return response()->json(['data' => $client], 201);
    }

    public function updateClient(Request $request, Client $client, AuditLogger $audit)
    {
        abort_unless($request->user()->can('clients.update'), 403);
        abort_unless($client->sales_owner_id === $request->user()->id, 404);
        $data = $request->validate([
            'company_name' => ['sometimes', 'required', 'string', 'max:180'], 'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'string', 'max:2000'], 'client_type' => ['sometimes', 'required', Rule::in(['LCL', 'FCL', 'Mixed'])],
        ]);
        $before = $client->only(array_keys($data));
        $client->update($data);
        $audit->record('clients.updated', $client, ['before' => $before, 'after' => $data]);

        return response()->json(['data' => $client->refresh()]);
    }

    public function inquiries(Request $request)
    {
        abort_unless($request->user()->can('inquiries.view'), 403);
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'search' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', 'string', 'max:24']]);

        return ClientInquiry::query()->with(['client:id,reference,company_name,client_type', 'owner:id,name'])
            ->where('sales_owner_id', $request->user()->id)
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $term = '%'.$search.'%';
                    $nested->where('reference', 'like', $term)
                        ->orWhere('origin', 'like', $term)
                        ->orWhere('destination', 'like', $term)
                        ->orWhereHas('client', fn (Builder $client) => $client->where('company_name', 'like', $term));
                });
            })
            ->latest()->paginate($filters['per_page'] ?? 15);
    }

    public function locationOptions(Request $request, FreightLocationCatalog $locations)
    {
        abort_unless($request->user()->can('inquiries.view') || $request->user()->can('inquiries.create') || $request->user()->can('inquiries.update'), 403);
        $filters = $request->validate([
            'mode' => ['required', Rule::in(['Sea', 'Air', 'Road', 'Rail'])],
            'country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'search' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json([
            'release' => config('freight_locations.release'),
            'countries' => $locations->countries(),
            'data' => $locations->search($filters['mode'], isset($filters['country']) ? strtoupper($filters['country']) : null, $filters['search'] ?? null),
        ]);
    }

    public function showInquiry(Request $request, ClientInquiry $inquiry)
    {
        abort_unless($request->user()->can('inquiries.view'), 403);
        $this->ownInquiry($request, $inquiry);

        return response()->json(['data' => $inquiry->load(['client:id,reference,company_name,client_type', 'owner:id,name'])]);
    }

    public function storeInquiry(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('inquiries.create'), 403);
        $data = $this->validatedInquiry($request, true);
        $client = Client::where('sales_owner_id', $request->user()->id)->findOrFail($data['client_id']);
        $inquiry = DB::transaction(function () use ($data, $request): ClientInquiry {
            $inquiry = ClientInquiry::create([...$data, 'sales_owner_id' => $request->user()->id, 'status' => 'draft', 'revision' => 1]);
            $inquiry->forceFill(['reference' => 'INQ-'.now()->format('Y').'-'.str_pad((string) $inquiry->id, 6, '0', STR_PAD_LEFT)])->save();

            return $inquiry->load('client');
        });
        $audit->record('inquiries.created', $inquiry, ['reference' => $inquiry->reference, 'client_id' => $client->id]);

        return response()->json(['data' => $inquiry], 201);
    }

    public function updateInquiry(Request $request, ClientInquiry $inquiry, AuditLogger $audit)
    {
        abort_unless($request->user()->can('inquiries.update'), 403);
        $this->ownInquiry($request, $inquiry);
        abort_unless($inquiry->status === 'draft', 409, 'Only draft inquiries may be edited.');
        $data = $this->validatedInquiry($request, false);
        if (isset($data['client_id'])) {
            Client::where('sales_owner_id', $request->user()->id)->findOrFail($data['client_id']);
        }
        $before = $inquiry->only(array_keys($data));
        $inquiry->fill($data);
        $inquiry->revision++;
        $inquiry->save();
        $audit->record('inquiries.updated', $inquiry, ['revision' => $inquiry->revision, 'before' => $before, 'after' => $data]);

        return response()->json(['data' => $inquiry->refresh()->load('client')]);
    }

    public function submitInquiry(Request $request, ClientInquiry $inquiry, AuditLogger $audit)
    {
        abort_unless($request->user()->can('inquiries.submit'), 403);
        $this->ownInquiry($request, $inquiry);
        abort_unless($inquiry->status === 'draft', 409);
        $submissionData = array_filter($inquiry->toArray(), static fn ($value): bool => $value !== null);
        unset($submissionData['id'], $submissionData['status'], $submissionData['revision'], $submissionData['reference'], $submissionData['created_at'], $submissionData['updated_at']);
        $this->validatedInquiry(Request::create('/api/sales/inquiries', 'POST', $submissionData), true);
        $inquiry->update(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $request->user()->id, 'revision' => $inquiry->revision + 1]);
        $audit->record('inquiries.submitted', $inquiry, ['revision' => $inquiry->revision, 'submitted_by' => $request->user()->id]);

        return response()->json(['data' => $inquiry->refresh()]);
    }

    public function transitionInquiry(Request $request, ClientInquiry $inquiry, AuditLogger $audit)
    {
        $this->ownInquiry($request, $inquiry);
        $data = $request->validate(['status' => ['required', Rule::in(['withdrawn', 'cancelled'])]]);
        $status = $data['status'];
        abort_unless($request->user()->can('inquiries.cancel'), 403);
        $transitions = ['submitted' => ['withdrawn', 'cancelled'], 'under_review' => ['withdrawn', 'cancelled'], 'forecasted' => ['withdrawn', 'cancelled']];
        abort_unless(in_array($status, $transitions[$inquiry->status] ?? [], true), 409, 'That inquiry status transition is not allowed.');
        $before = $inquiry->status;
        $inquiry->update(['status' => $status, 'revision' => $inquiry->revision + 1]);
        $audit->record('inquiries.status_changed', $inquiry, ['before' => $before, 'after' => $status, 'revision' => $inquiry->revision]);

        return response()->json(['data' => $inquiry->refresh()]);
    }

    public function forecasts(Request $request)
    {
        abort_unless($request->user()->can('forecasts.view'), 403);
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'month' => ['nullable', 'date_format:Y-m']]);

        return $this->ownedForecastQuery($request)->with(['inquiry.client:id,company_name', 'owner:id,name'])
            ->when($filters['month'] ?? null, fn (Builder $q, $month) => $q->where('forecast_month', $month.'-01'))
            ->orderBy('forecast_month')->paginate($filters['per_page'] ?? 50);
    }

    public function storeForecast(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('forecasts.create'), 403);
        $data = $this->validatedForecast($request);
        $inquiry = ClientInquiry::where('sales_owner_id', $request->user()->id)->whereIn('status', ['submitted', 'under_review', 'forecasted'])->findOrFail($data['inquiry_id']);
        $this->assertForecastMonthAvailable($data['inquiry_id'], $data['forecast_month']);
        $forecast = DB::transaction(function () use ($data, $request, $audit, $inquiry): ShipmentForecast {
            $forecast = ShipmentForecast::create([...$data, 'sales_owner_id' => $request->user()->id, 'status' => 'active', 'revision' => 1]);
            ForecastRevision::create(['forecast_id' => $forecast->id, 'actor_id' => $request->user()->id, 'revision' => 1, 'action' => 'created', 'after_values' => $forecast->only(['forecast_month', 'expected_shipment_count', 'confidence', 'status'])]);
            if (in_array($inquiry->status, ['submitted', 'under_review'], true)) {
                $before = $inquiry->status;
                $inquiry->update(['status' => 'forecasted', 'revision' => $inquiry->revision + 1]);
                $audit->record('inquiries.status_changed', $inquiry, ['before' => $before, 'after' => 'forecasted', 'revision' => $inquiry->revision]);
            }

            return $forecast;
        });
        $audit->record('forecasts.created', $forecast, ['inquiry_id' => $inquiry->id, 'revision' => 1, 'after' => $forecast->only(['forecast_month', 'expected_shipment_count', 'confidence', 'status'])]);

        return response()->json(['data' => $forecast], 201);
    }

    public function updateForecast(Request $request, ShipmentForecast $forecast, AuditLogger $audit)
    {
        abort_unless($request->user()->can('forecasts.update'), 403);
        $this->ownForecast($request, $forecast);
        $data = $this->validatedForecast($request, false);
        if (isset($data['forecast_month'])) {
            $this->assertForecastMonthAvailable($forecast->inquiry_id, $data['forecast_month'], $forecast->id);
        }
        DB::transaction(function () use ($forecast, $data, $request, $audit): void {
            $locked = ShipmentForecast::query()->lockForUpdate()->findOrFail($forecast->id);
            $before = $locked->only(array_keys($data));
            $locked->fill($data);
            $locked->revision++;
            $locked->save();
            $after = $locked->only(['forecast_month', 'expected_shipment_count', 'confidence', 'status']);
            ForecastRevision::create(['forecast_id' => $locked->id, 'actor_id' => $request->user()->id, 'revision' => $locked->revision, 'action' => 'updated', 'before_values' => $before, 'after_values' => $after]);
            $audit->record('forecasts.updated', $locked, ['revision' => $locked->revision, 'before' => $before, 'after' => $after]);
        });

        return response()->json(['data' => $forecast->refresh()]);
    }

    public function withdrawForecast(Request $request, ShipmentForecast $forecast, AuditLogger $audit)
    {
        abort_unless($request->user()->can('forecasts.withdraw'), 403);
        $this->ownForecast($request, $forecast);
        abort_unless($forecast->status === 'active', 409);
        DB::transaction(function () use ($forecast, $request, $audit): void {
            $before = $forecast->only(['forecast_month', 'expected_shipment_count', 'confidence', 'status']);
            $forecast->update(['status' => 'withdrawn', 'revision' => $forecast->revision + 1]);
            $after = $forecast->only(['forecast_month', 'expected_shipment_count', 'confidence', 'status']);
            ForecastRevision::create(['forecast_id' => $forecast->id, 'actor_id' => $request->user()->id, 'revision' => $forecast->revision, 'action' => 'withdrawn', 'before_values' => $before, 'after_values' => $after]);
            $audit->record('forecasts.withdrawn', $forecast, ['revision' => $forecast->revision, 'before' => $before, 'after' => $after]);
            $inquiry = ClientInquiry::query()->findOrFail($forecast->inquiry_id);
            if ($inquiry->status === 'forecasted' && ! ShipmentForecast::query()->where('inquiry_id', $inquiry->id)->where('status', 'active')->exists()) {
                $inquiry->update(['status' => 'submitted', 'revision' => $inquiry->revision + 1]);
                $audit->record('inquiries.status_changed', $inquiry, ['before' => 'forecasted', 'after' => 'submitted', 'revision' => $inquiry->revision]);
            }
        });

        return response()->json(['data' => $forecast->refresh()]);
    }

    public function history(Request $request)
    {
        abort_unless($request->user()->can('forecasts.view'), 403);
        $ids = $this->ownedForecastQuery($request)->select('shipment_forecasts.id');

        return ForecastRevision::query()->with(['forecast:id,inquiry_id,forecast_month', 'actor:id,name'])->whereIn('forecast_id', $ids)->orderByDesc('created_at')->orderByDesc('id')->paginate(30);
    }

    public function accountingForecasts(Request $request)
    {
        abort_unless($request->user()->can('forecasts.accounting.view'), 403);
        $rows = ShipmentForecast::query()->with(['inquiry:id,reference,origin,destination,transport_mode,service_type,client_id', 'inquiry.client:id,company_name,client_type'])
            ->where('status', 'active')->whereIn('confidence', ['probable', 'confirmed'])
            ->when($request->month, fn (Builder $q, $month) => $q->where('forecast_month', $month.'-01'))
            ->orderBy('forecast_month')->paginate(50);

        return $rows->through(fn (ShipmentForecast $forecast) => [
            'reference' => $forecast->inquiry->reference, 'month' => $forecast->forecast_month->format('Y-m'),
            'client' => $forecast->inquiry->client->company_name, 'client_type' => $forecast->inquiry->client->client_type,
            'service_type' => $forecast->inquiry->service_type, 'transport_mode' => $forecast->inquiry->transport_mode,
            'origin' => $forecast->inquiry->origin, 'destination' => $forecast->inquiry->destination,
            'expected_shipments' => $forecast->expected_shipment_count, 'confidence' => $forecast->confidence,
            'estimated_shipping_fee' => null, 'cash_advance' => null, 'cost_estimate_status' => 'not_estimated',
        ]);
    }

    public function accountingMonthly(Request $request)
    {
        abort_unless($request->user()->can('forecasts.accounting.view'), 403);
        $filters = $request->validate(['from' => ['nullable', 'date_format:Y-m'], 'to' => ['nullable', 'date_format:Y-m', 'after_or_equal:from']]);
        $query = ShipmentForecast::query()->where('status', 'active')->whereIn('confidence', ['probable', 'confirmed']);
        if (! empty($filters['from'])) {
            $query->where('forecast_month', '>=', $filters['from'].'-01');
        }
        if (! empty($filters['to'])) {
            $query->where('forecast_month', '<=', $filters['to'].'-01');
        }

        return response()->json(['data' => $query->selectRaw('forecast_month, SUM(expected_shipment_count) as expected_shipments, COUNT(*) as forecast_rows')
            ->groupBy('forecast_month')->orderBy('forecast_month')->get()->map(fn ($row) => [...$row->toArray(), 'estimated_shipping_fee' => null, 'cash_advance' => null, 'cost_estimate_status' => 'not_estimated'])]);
    }

    private function validatedInquiry(Request $request, bool $required): array
    {
        $presence = $required ? ['required'] : ['sometimes'];
        $data = $request->validate([
            'client_id' => [...$presence, 'integer', Rule::exists('clients', 'id')],
            'service_type' => [...$presence, Rule::in(['LCL', 'FCL'])],
            'transport_mode' => [...$presence, Rule::in(['Sea', 'Air', 'Road', 'Rail'])],
            'origin' => [$required ? 'required_without_all:origin_location_id,origin_custom_details' : 'sometimes', 'nullable', 'string', 'max:180'],
            'destination' => [$required ? 'required_without_all:destination_location_id,destination_custom_details' : 'sometimes', 'nullable', 'string', 'max:180'],
            'origin_country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'origin_country_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'origin_location_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'origin_location_type' => ['sometimes', 'nullable', Rule::in(['seaport', 'container_terminal', 'airport', 'city', 'logistics_hub', 'delivery_location', 'custom'])],
            'origin_location_code_system' => ['sometimes', 'nullable', Rule::in(['unlocode', 'iata', 'custom'])],
            'origin_location_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'origin_custom_details' => ['sometimes', 'nullable', 'string', 'max:180'],
            'destination_country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'destination_country_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'destination_location_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'destination_location_type' => ['sometimes', 'nullable', Rule::in(['seaport', 'container_terminal', 'airport', 'city', 'logistics_hub', 'delivery_location', 'custom'])],
            'destination_location_code_system' => ['sometimes', 'nullable', Rule::in(['unlocode', 'iata', 'custom'])],
            'destination_location_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'destination_custom_details' => ['sometimes', 'nullable', 'string', 'max:180'],
            'cargo_description' => [...$presence, 'string', 'max:220'],
            'estimated_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'estimated_volume_cbm' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'estimated_container_count' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'container_type' => ['nullable', 'string', 'max:30'], 'commodity_category' => ['nullable', 'string', 'max:100'],
            'special_requirements' => ['nullable', 'string', 'max:4000'], 'requested_ship_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $locations = app(FreightLocationCatalog::class);
        foreach (['origin', 'destination'] as $prefix) {
            $structuredFields = ["{$prefix}_country_code", "{$prefix}_country_name", "{$prefix}_location_id", "{$prefix}_location_type", "{$prefix}_location_code_system", "{$prefix}_location_name", "{$prefix}_custom_details"];
            $hasStructured = collect($structuredFields)->contains(fn (string $field): bool => $request->exists($field));
            if ($hasStructured) {
                $data = $locations->normalizeSubmittedLocation($data, $prefix, $data['transport_mode'] ?? $request->input('transport_mode', 'Sea'));
            } elseif (array_key_exists($prefix, $data) && ! $required) {
                foreach ($structuredFields as $field) {
                    $data[$field] = null;
                }
            }
        }

        return $data;
    }

    private function validatedForecast(Request $request, bool $required = true): array
    {
        $presence = $required ? ['required'] : ['sometimes'];
        $rules = [
            'forecast_month' => [...$presence, 'date_format:Y-m-d', 'regex:/-01$/', 'after_or_equal:'.now()->startOfMonth()->toDateString()],
            'expected_shipment_count' => [...$presence, 'integer', 'min:1', 'max:10000'],
            'confidence' => [...$presence, Rule::in(['tentative', 'probable', 'confirmed'])],
            'status' => ['sometimes', Rule::in(['active'])],
        ];
        if ($required) {
            $rules['inquiry_id'] = ['required', 'integer', Rule::exists('client_inquiries', 'id')];
        }

        return $request->validate($rules);
    }

    private function ownInquiry(Request $request, ClientInquiry $inquiry): void
    {
        abort_unless($inquiry->sales_owner_id === $request->user()->id, 404);
    }

    private function ownForecast(Request $request, ShipmentForecast $forecast): void
    {
        abort_unless($forecast->sales_owner_id === $request->user()->id, 404);
    }

    private function assertForecastMonthAvailable(int|string $inquiryId, string $month, ?int $exceptId = null): void
    {
        $exists = ShipmentForecast::query()->where('inquiry_id', $inquiryId)->whereDate('forecast_month', $month)
            ->when($exceptId, fn (Builder $query) => $query->where('id', '<>', $exceptId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['forecast_month' => 'A forecast already exists for this inquiry and month. Edit the existing forecast instead.']);
        }
    }

    private function ownedForecastQuery(Request $request): Builder
    {
        return ShipmentForecast::query()->where('sales_owner_id', $request->user()->id);
    }
}
