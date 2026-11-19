<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\FinancialPolicyRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinancialPolicyController
{
    public function index(Request $request, FinancialPolicyRegistry $registry)
    {
        abort_unless($request->user()->hasRole('Finance'), 403);
        $rows = DB::table('financial_policy_versions')->orderByDesc('version')->paginate(20);

        return response()->json(['data' => $rows, 'active' => $registry->active()]);
    }

    public function propose(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->hasRole('Finance') && $request->user()->can('financial-policies.manage'), 403);
        $data = $request->validate([
            'revenue_recognition.source' => ['required', 'string', 'min:3', 'max:160'],
            'revenue_recognition.rule' => ['required', 'string', 'min:10', 'max:2000'],
            'final_cost_accrual.source' => ['required', 'string', 'min:3', 'max:160'],
            'final_cost_accrual.method' => ['required', 'string', 'min:3', 'max:160'],
            'reporting_currency' => ['required', 'string', 'size:3', 'alpha', 'uppercase'],
            'fx.source' => ['required', 'string', 'min:3', 'max:160'],
            'fx.pairs' => ['required', 'array', 'max:30'],
            'fx.pairs.*' => ['required', 'string', 'regex:/^[A-Z]{3}\/[A-Z]{3}$/', 'distinct'],
            'effective_from' => ['required', 'date'],
        ]);
        $policy = [
            'revenue_recognition' => $data['revenue_recognition'],
            'final_cost_accrual' => $data['final_cost_accrual'],
            'reporting_currency' => $data['reporting_currency'],
            'fx' => $data['fx'],
        ];
        $row = DB::transaction(function () use ($request, $data, $policy): object {
            $version = (int) DB::table('financial_policy_versions')->lockForUpdate()->max('version') + 1;
            $id = DB::table('financial_policy_versions')->insertGetId([
                'version' => $version, 'status' => 'pending_approval', 'policy' => json_encode($policy, JSON_THROW_ON_ERROR),
                'submitted_by' => $request->user()->id, 'submitted_at' => now(), 'effective_from' => $data['effective_from'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($id, $request->user()->id, 'proposed', null, $policy, 'Policy submitted for independent Finance approval.');

            return DB::table('financial_policy_versions')->where('id', $id)->first();
        });
        $audit->record('financial_policy.proposed', null, ['policy_version_id' => $row->id, 'version' => $row->version, 'status' => $row->status]);

        return response()->json(['data' => $row, 'message' => 'Policy version recorded as pending approval; it is not active.'], 201);
    }

    public function approve(Request $request, int $version, AuditLogger $audit)
    {
        abort_unless($request->user()->hasRole('Finance') && $request->user()->can('financial-policies.approve'), 403);
        $data = $request->validate([
            'approval_reference' => ['required', 'string', 'min:4', 'max:160'],
            'confirmation' => ['required', Rule::in(['finance_approved'])],
        ]);
        $row = DB::transaction(function () use ($request, $version, $data): object {
            $policy = DB::table('financial_policy_versions')->where('version', $version)->lockForUpdate()->firstOrFail();
            abort_unless($policy->status === 'pending_approval', 409, 'Only a pending policy version can be approved.');
            abort_unless((int) $policy->submitted_by !== (int) $request->user()->id, 403, 'The proposer cannot approve their own policy.');
            DB::table('financial_policy_versions')->where('status', 'approved')->whereNull('superseded_at')->update(['superseded_at' => $policy->effective_from, 'updated_at' => now()]);
            DB::table('financial_policy_versions')->where('id', $policy->id)->update([
                'status' => 'approved', 'approved_by' => $request->user()->id, 'approval_reference' => $data['approval_reference'],
                'approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($policy->id, $request->user()->id, 'approved', $policy->policy, $policy->policy, $data['approval_reference']);

            return DB::table('financial_policy_versions')->where('id', $policy->id)->first();
        });
        $audit->record('financial_policy.approved', null, ['policy_version_id' => $row->id, 'version' => $row->version, 'approval_reference' => $row->approval_reference]);

        return response()->json(['data' => $row, 'message' => 'Finance policy approval recorded. Verify authoritative source integration before using it for closing.']);
    }

    public function reject(Request $request, int $version, AuditLogger $audit)
    {
        abort_unless($request->user()->hasRole('Finance') && $request->user()->can('financial-policies.approve'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $row = DB::transaction(function () use ($request, $version, $data): object {
            $policy = DB::table('financial_policy_versions')->where('version', $version)->lockForUpdate()->firstOrFail();
            abort_unless($policy->status === 'pending_approval', 409);
            abort_unless((int) $policy->submitted_by !== (int) $request->user()->id, 403);
            DB::table('financial_policy_versions')->where('id', $policy->id)->update(['status' => 'rejected', 'rejected_by' => $request->user()->id, 'decision_reason' => $data['reason'], 'updated_at' => now()]);
            $this->event($policy->id, $request->user()->id, 'rejected', $policy->policy, $policy->policy, $data['reason']);

            return DB::table('financial_policy_versions')->where('id', $policy->id)->first();
        });
        $audit->record('financial_policy.rejected', null, ['policy_version_id' => $row->id, 'version' => $row->version, 'reason' => $data['reason']]);

        return response()->json(['data' => $row]);
    }

    private function event(int $id, int $actor, string $event, ?string $before, array|string $after, ?string $reason): void
    {
        $payload = is_array($after) ? json_encode($after, JSON_THROW_ON_ERROR) : $after;
        DB::table('financial_policy_events')->insert([
            'policy_version_id' => $id, 'actor_id' => $actor, 'event' => $event,
            'policy_sha256' => hash('sha256', $payload), 'reason' => $reason, 'created_at' => now(),
        ]);
    }
}
