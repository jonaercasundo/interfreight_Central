<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\ShipmentClosingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentClosingController extends Controller
{
    public function page(Request $request)
    {
        abort_unless(($request->user()->can('financial-closing.view') || $request->user()->can('shipments.completion.view')) && $this->hasBusinessRole($request), 403);

        return view('app', ['page' => 'shipment-closing']);
    }

    public function index(Request $request)
    {
        abort_unless(($request->user()->can('financial-closing.view') || $request->user()->can('shipments.completion.view')) && $this->hasBusinessRole($request), 403);
        $page = DB::table('shipments as s')->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->whereNull('s.archived_at')->whereNotIn('s.master_status', ['cancelled'])
            ->when(! $this->hasBroadShipmentScope($request), function ($query) use ($request): void {
                $departments = array_values(array_intersect(['Operations', 'Transport', 'Forwarding'], $request->user()->getRoleNames()->all()));
                abort_unless($departments || $request->user()->hasRole('Billing'), 403);
                if ($departments) {
                    $query->whereExists(function ($assignment) use ($departments, $request): void {
                        $assignment->selectRaw('1')->from('shipment_department_assignments as a')->whereColumn('a.shipment_id', 's.id')->whereIn('a.department', $departments)->where(fn ($q) => $q->whereNull('a.assigned_user_id')->orWhere('a.assigned_user_id', $request->user()->id));
                    });
                }
            })
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($q) => $q->where('s.job_number', 'like', $term)->orWhere('s.reference', 'like', $term)->orWhere('c.company_name', 'like', $term));
            })
            ->select('s.id', 's.job_number', 's.reference', 's.service_type', 's.mode', 's.currency', 's.originating_department', 'c.company_name')
            ->orderByDesc('s.id')->paginate(min(50, max(1, $request->integer('per_page', 20))));
        $ids = $page->getCollection()->pluck('id');
        $milestones = DB::table('shipment_completion_milestones')->whereIn('shipment_id', $ids)->get()->groupBy('shipment_id');
        $requests = DB::table('financial_closing_requests')->whereIn('shipment_id', $ids)->orderByDesc('version')->get()->unique('shipment_id')->keyBy('shipment_id');
        $page->setCollection($page->getCollection()->map(function ($row) use ($milestones, $requests): array {
            $statuses = $milestones->get($row->id, collect())->keyBy('dimension');
            $dimensions = [];
            foreach (['operations', 'billing', 'collections', 'expense_reconciliation'] as $dimension) {
                $dimensions[$dimension] = ['status' => $statuses->get($dimension)->status ?? 'not_started'];
            }

            return [
                'shipment' => ['id' => $row->id, 'job_number' => $row->job_number ?: $row->reference, 'client' => $row->company_name, 'service' => $row->service_type, 'mode' => $row->mode, 'department' => $row->originating_department, 'currency' => $row->currency],
                'milestones' => $dimensions,
                'closing' => ['status' => $requests->get($row->id)->status ?? 'open'],
            ];
        }));

        return response()->json(['data' => $page]);
    }

    public function show(Request $request, int $shipment, ShipmentClosingService $closing)
    {
        abort_unless(($request->user()->can('financial-closing.view') || $request->user()->can('shipments.completion.view')) && $this->hasBusinessRole($request), 403);
        $this->assertShipmentScope($request, $shipment);
        $data = $closing->detail($shipment);
        $data = $this->filterFinancialData($request, $data);

        return response()->json(['data' => $data]);
    }

    public function verify(Request $request, int $shipment, string $dimension, ShipmentClosingService $closing, AuditLogger $audit)
    {
        $permission = match ($dimension) {
            'operations' => 'shipments.completion.operations.verify',
            'billing' => 'shipments.completion.billing.verify',
            'collections' => 'shipments.completion.collections.verify',
            'expense_reconciliation' => 'shipments.reconciliation.manage',
            default => abort(404),
        };
        abort_unless($request->user()->can($permission) && $this->hasBusinessRole($request), 403);
        $this->assertShipmentScope($request, $shipment);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:1']]);
        $detail = $closing->detail($shipment);
        abort_if($detail['milestones'][$dimension]['blockers'], 409, 'This completion milestone is blocked by unresolved source records.');

        $milestone = DB::transaction(function () use ($request, $shipment, $dimension, $data): object {
            $existing = DB::table('shipment_completion_milestones')->where(['shipment_id' => $shipment, 'dimension' => $dimension])->lockForUpdate()->first();
            abort_if($existing && (int) $existing->lock_version !== (int) $data['lock_version'], 409, 'This completion record changed. Refresh and try again.');
            $now = now();
            if ($existing) {
                DB::table('shipment_completion_milestones')->where('id', $existing->id)->update(['status' => 'completed', 'notes' => $data['notes'] ?? null, 'verified_by' => $request->user()->id, 'verified_at' => $now, 'lock_version' => $existing->lock_version + 1, 'updated_at' => $now]);
                $id = $existing->id;
            } else {
                $id = DB::table('shipment_completion_milestones')->insertGetId(['shipment_id' => $shipment, 'dimension' => $dimension, 'status' => 'completed', 'notes' => $data['notes'] ?? null, 'verified_by' => $request->user()->id, 'verified_at' => $now, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }

            return DB::table('shipment_completion_milestones')->where('id', $id)->first();
        });
        $audit->record('shipment.completion.verified', null, ['shipment_id' => $shipment, 'dimension' => $dimension, 'milestone_id' => $milestone->id, 'verified_by' => $request->user()->id]);

        return response()->json(['message' => 'Completion milestone verified.', 'data' => $this->filterFinancialData($request, $closing->detail($shipment))]);
    }

    public function prepare(Request $request, int $shipment, ShipmentClosingService $closing, AuditLogger $audit)
    {
        abort_unless($request->user()->can('financial-closing.prepare') && $this->hasBusinessRole($request), 403);
        $this->assertShipmentScope($request, $shipment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $detail = $closing->detail($shipment);
        abort_if($detail['closing']['blockers'], 409, 'Shipment closing is blocked. Resolve the listed source records first.');
        $created = DB::transaction(function () use ($request, $shipment, $data): object {
            $latest = DB::table('financial_closing_requests')->where('shipment_id', $shipment)->orderByDesc('version')->lockForUpdate()->first();
            abort_if($latest && in_array($latest->status, ['pending_approval', 'financially_closed'], true), 409, 'A closing request is already pending or closed.');
            $version = (int) ($latest->version ?? 0) + 1;
            $id = DB::table('financial_closing_requests')->insertGetId(['shipment_id' => $shipment, 'status' => 'pending_approval', 'version' => $version, 'prepared_by' => $request->user()->id, 'submitted_at' => now(), 'resolution' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('financial_closing_reviews')->insert(['closing_request_id' => $id, 'actor_id' => $request->user()->id, 'action' => 'submitted', 'comments' => $data['reason'], 'acted_at' => now()]);

            return DB::table('financial_closing_requests')->where('id', $id)->first();
        });
        $audit->record('financial_closing.submitted', null, ['shipment_id' => $shipment, 'request_id' => $created->id, 'version' => $created->version]);

        return response()->json(['message' => 'Shipment submitted for executive approval.', 'data' => $closing->detail($shipment)], 201);
    }

    public function review(Request $request, int $shipment, AuditLogger $audit)
    {
        abort_unless($request->user()->can('financial-closing.approve') && $this->hasBusinessRole($request), 403);
        $this->assertShipmentScope($request, $shipment);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject,request_correction'], 'comments' => ['required', 'string', 'min:5', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:1']]);
        $result = DB::transaction(function () use ($request, $shipment, $data): object {
            $closing = DB::table('financial_closing_requests')->where('shipment_id', $shipment)->where('status', 'pending_approval')->orderByDesc('version')->lockForUpdate()->first();
            abort_unless($closing, 404);
            abort_if((int) $closing->lock_version !== (int) $data['lock_version'], 409, 'This closing request changed. Refresh and try again.');
            abort_if((int) $closing->prepared_by === (int) $request->user()->id, 403, 'The preparer cannot approve their own closing request.');
            $status = match ($data['decision']) {
                'approve' => 'approved', 'reject' => 'rejected', default => 'correction_requested'
            };
            DB::table('financial_closing_requests')->where('id', $closing->id)->update(['status' => $status, 'resolution' => $data['comments'], 'lock_version' => $closing->lock_version + 1, 'updated_at' => now()]);
            DB::table('financial_closing_reviews')->insert(['closing_request_id' => $closing->id, 'actor_id' => $request->user()->id, 'action' => $data['decision'], 'comments' => $data['comments'], 'acted_at' => now()]);

            return DB::table('financial_closing_requests')->where('id', $closing->id)->first();
        });
        $audit->record('financial_closing.reviewed', null, ['shipment_id' => $shipment, 'request_id' => $result->id, 'decision' => $data['decision']]);

        return response()->json(['message' => 'Closing review recorded.', 'data' => $result]);
    }

    public function close(Request $request, int $shipment, ShipmentClosingService $closing, AuditLogger $audit)
    {
        abort_unless($request->user()->can('financial-closing.close') && $this->hasBusinessRole($request), 403);
        $this->assertShipmentScope($request, $shipment);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $current = $closing->detail($shipment);
        abort_if($current['closing']['blockers'], 409, 'Financial closing is blocked by unresolved source records.');
        $outcome = DB::transaction(function () use ($request, $shipment, $data, $closing): array {
            $shipmentRow = DB::table('shipments')->where('id', $shipment)->lockForUpdate()->first();
            abort_unless($shipmentRow, 404);
            $requestRow = DB::table('financial_closing_requests')->where('shipment_id', $shipment)->where('status', 'approved')->orderByDesc('version')->lockForUpdate()->first();
            abort_unless($requestRow, 409, 'Executive approval is required before financial closing.');
            abort_if((int) $requestRow->lock_version !== (int) $data['lock_version'], 409, 'This closing request changed. Refresh and try again.');
            abort_if((int) $requestRow->prepared_by === (int) $request->user()->id, 403, 'The preparer cannot close their own request.');
            $approval = DB::table('financial_closing_reviews')->where('closing_request_id', $requestRow->id)->where('action', 'approve')->latest('acted_at')->first();
            abort_unless($approval, 409, 'A separate Executive approval is required before closing.');
            abort_if((int) $approval->actor_id === (int) $request->user()->id, 403, 'The Executive approver cannot finalize the same closing request.');
            $this->lockSourceRows($shipment);
            $detail = $closing->detail($shipment);
            abort_if($detail['closing']['blockers'], 409, 'Financial source data changed; refresh the reconciliation before closing.');
            $snapshot = ['schema' => 'ifc.shipment-closing.v1', 'captured_at' => now()->toIso8601String(), 'shipment' => $detail['shipment'], 'milestones' => $detail['milestones'], 'financial' => $detail['financial'], 'request_version' => $requestRow->version, 'approvals' => DB::table('financial_closing_reviews')->where('closing_request_id', $requestRow->id)->orderBy('acted_at')->get()->toArray(), 'source_cutoff' => now()->toIso8601String(), 'recognition_policy' => 'not_configured'];
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $version = (int) $requestRow->version;
            DB::table('financial_closing_snapshots')->insert(['shipment_id' => $shipment, 'closing_request_id' => $requestRow->id, 'version' => $version, 'currency' => $detail['shipment']['currency'], 'snapshot' => $json, 'sha256' => hash('sha256', $json), 'created_by' => $request->user()->id, 'created_at' => now()]);
            DB::table('financial_closing_requests')->where('id', $requestRow->id)->update(['status' => 'financially_closed', 'closed_by' => $request->user()->id, 'closed_at' => now(), 'lock_version' => $requestRow->lock_version + 1, 'updated_at' => now()]);
            DB::table('financial_closing_reviews')->insert(['closing_request_id' => $requestRow->id, 'actor_id' => $request->user()->id, 'action' => 'financially_closed', 'comments' => null, 'acted_at' => now()]);

            return ['request_id' => $requestRow->id, 'version' => $version, 'snapshot_sha256' => hash('sha256', $json)];
        });
        $audit->record('financial_closing.closed', null, ['shipment_id' => $shipment, ...$outcome]);

        return response()->json(['message' => 'Shipment financially closed.', 'data' => $closing->detail($shipment)]);
    }

    public function history(Request $request, int $shipment)
    {
        abort_unless(($request->user()->can('financial-closing.history.view') || $request->user()->can('financial-closing.snapshots.view')) && $this->hasBusinessRole($request), 403);
        $this->assertShipmentScope($request, $shipment);
        $snapshots = DB::table('financial_closing_snapshots')->where('shipment_id', $shipment)->orderByDesc('version')->get();
        $reviews = DB::table('financial_closing_reviews as r')->join('financial_closing_requests as q', 'q.id', '=', 'r.closing_request_id')->where('q.shipment_id', $shipment)->orderByDesc('r.acted_at')->get(['r.*']);

        return response()->json(['data' => ['snapshots' => $snapshots, 'reviews' => $reviews]]);
    }

    public function reopen(Request $request, int $shipment, AuditLogger $audit)
    {
        abort_unless($request->user()->can('financial-closing.reopen') && $this->hasBusinessRole($request), 403);
        $this->assertShipmentScope($request, $shipment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $row = DB::transaction(function () use ($request, $shipment, $data): object {
            $current = DB::table('financial_closing_requests')->where('shipment_id', $shipment)->where('status', 'financially_closed')->orderByDesc('version')->lockForUpdate()->first();
            abort_unless($current, 409, 'Only a financially closed shipment can be reopened.');
            DB::table('financial_closing_requests')->where('id', $current->id)->update(['status' => 'reopened', 'resolution' => $data['reason'], 'lock_version' => $current->lock_version + 1, 'updated_at' => now()]);
            DB::table('financial_closing_reviews')->insert(['closing_request_id' => $current->id, 'actor_id' => $request->user()->id, 'action' => 'reopened', 'comments' => $data['reason'], 'acted_at' => now()]);

            return DB::table('financial_closing_requests')->where('id', $current->id)->first();
        });
        $audit->record('financial_closing.reopened', null, ['shipment_id' => $shipment, 'request_id' => $row->id, 'reason' => $data['reason']]);

        return response()->json(['message' => 'Shipment reopened for a controlled adjustment. Historical snapshots remain unchanged.', 'data' => $row]);
    }

    private function hasBroadShipmentScope(Request $request): bool
    {
        return $request->user()->hasAnyRole(['Accounting', 'Finance', 'Executive']);
    }

    private function hasBusinessRole(Request $request): bool
    {
        return $request->user()->hasAnyRole(['Executive', 'Sales', 'Operations', 'Transport', 'Forwarding', 'Accounting', 'Billing', 'Finance']);
    }

    private function assertShipmentScope(Request $request, int $shipment): void
    {
        if ($this->hasBroadShipmentScope($request) || $request->user()->hasRole('Billing')) {
            return;
        }
        $departments = array_values(array_intersect(['Operations', 'Transport', 'Forwarding'], $request->user()->getRoleNames()->all()));
        abort_unless($departments && DB::table('shipment_department_assignments')->where('shipment_id', $shipment)->whereIn('department', $departments)->where(fn ($query) => $query->whereNull('assigned_user_id')->orWhere('assigned_user_id', $request->user()->id))->exists(), 404);
    }

    private function filterFinancialData(Request $request, array $data): array
    {
        if (! $request->user()->can('financial-closing.view') && ! $request->user()->can('profitability.shipments.view')) {
            $data['financial'] = ['status' => 'restricted'];
            $data['closing'] = ['status' => 'restricted'];
        }
        if (! $request->user()->can('shipments.completion.billing.verify') && ! $request->user()->can('financial-closing.view')) {
            $data['milestones']['billing']['facts'] = ['status' => 'restricted'];
        }
        if (! $request->user()->can('shipments.completion.collections.verify') && ! $request->user()->can('financial-closing.view')) {
            $data['milestones']['collections']['facts'] = ['status' => 'restricted'];
        }
        if (! $request->user()->can('profitability.cost-details.view')) {
            foreach (['recognized_revenue', 'final_cost', 'gross_profit', 'gross_margin_percent', 'estimated_approved_cost', 'estimated_gross_profit', 'estimated_gross_margin_percent'] as $key) {
                if (isset($data['financial'][$key])) {
                    $data['financial'][$key] = null;
                }
            }
            if (isset($data['milestones']['expense_reconciliation']['facts'])) {
                $data['milestones']['expense_reconciliation']['facts'] = ['status' => 'restricted'];
            }
        }

        return $data;
    }

    private function lockSourceRows(int $shipment): void
    {
        $receiptIds = DB::table('collection_payment_allocations as a')
            ->join('billing_invoices as i', 'i.id', '=', 'a.invoice_id')
            ->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->where('i.shipment_id', $shipment)->orderBy('r.id')->pluck('r.id');
        DB::table('collection_payment_receipts')->whereIn('id', $receiptIds)->orderBy('id')->lockForUpdate()->get(['id']);

        foreach ([
            'billing_invoices', 'shipment_completion_milestones', 'shipment_milestones', 'shipment_transport_trips',
            'shipment_forwarding_bookings', 'shipment_containers', 'shipment_deliveries', 'shipment_documents',
            'shipment_exceptions', 'shipment_customs_processes', 'actual_expenses', 'cash_advances',
            'additional_charge_requests', 'commercial_change_authorizations',
        ] as $table) {
            DB::table($table)->where('shipment_id', $shipment)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }
}
