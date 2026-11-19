<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\StatutoryBillingRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatutoryBillingController
{
    public function index(Request $request, StatutoryBillingRegistry $registry)
    {
        abort_unless($request->user()->hasRole('Finance') && $request->user()->can('billing.statutory.view'), 403);

        return response()->json(['data' => DB::table('billing_issuance_profiles')->orderByDesc('version')->paginate(20), 'active' => $registry->active()]);
    }

    public function propose(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->hasRole('Finance') && $request->user()->can('billing.statutory.manage'), 403);
        $data = $request->validate([
            'legal_entity.legal_name' => ['required', 'string', 'min:2', 'max:180'],
            'legal_entity.country_code' => ['required', 'string', 'size:2', 'alpha', 'uppercase'],
            'legal_entity.tax_registration_id' => ['required', 'string', 'min:2', 'max:120'],
            'legal_entity.registered_address' => ['required', 'string', 'min:5', 'max:2000'],
            'tax_rules' => ['required', 'array', 'min:1', 'max:100'],
            'tax_rules.*.code' => ['required', 'string', 'max:80'],
            'tax_rules.*.jurisdiction' => ['required', 'string', 'max:160'],
            'tax_rules.*.treatment' => ['required', 'string', 'max:160'],
            'tax_rules.*.rate' => ['nullable', 'numeric', 'min:0', 'max:999.999999'],
            'tax_rules.*.effective_from' => ['required', 'date'],
            'numbering_rules.prefix' => ['required', 'string', 'max:24'],
            'numbering_rules.pattern' => ['required', 'string', 'min:4', 'max:120'],
            'numbering_rules.reset_rule' => ['required', 'string', 'max:80'],
            'document_rules.template_identifier' => ['required', 'string', 'max:120'],
            'document_rules.required_fields' => ['required', 'array', 'min:1', 'max:100'],
            'document_rules.required_fields.*' => ['required', 'string', 'max:120'],
            'effective_from' => ['required', 'date'],
        ]);
        $profile = [
            'legal_entity' => $data['legal_entity'], 'tax_rules' => $data['tax_rules'],
            'numbering_rules' => $data['numbering_rules'], 'document_rules' => $data['document_rules'],
        ];
        $row = DB::transaction(function () use ($request, $data, $profile): object {
            $version = (int) DB::table('billing_issuance_profiles')->lockForUpdate()->max('version') + 1;
            $id = DB::table('billing_issuance_profiles')->insertGetId([
                'version' => $version, 'status' => 'pending_approval',
                'legal_entity' => json_encode($profile['legal_entity'], JSON_THROW_ON_ERROR),
                'tax_rules' => json_encode($profile['tax_rules'], JSON_THROW_ON_ERROR),
                'numbering_rules' => json_encode($profile['numbering_rules'], JSON_THROW_ON_ERROR),
                'document_rules' => json_encode($profile['document_rules'], JSON_THROW_ON_ERROR),
                'submitted_by' => $request->user()->id, 'submitted_at' => now(),
                'effective_from' => $data['effective_from'], 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('billing_issuance_profiles')->where('id', $id)->first();
        });
        $audit->record('billing.statutory_profile.proposed', null, ['profile_id' => $row->id, 'version' => $row->version, 'status' => $row->status]);

        return response()->json(['data' => $row, 'message' => 'Statutory profile recorded as pending independent approval. Issuance remains disabled.'], 201);
    }

    public function approve(Request $request, int $version, AuditLogger $audit)
    {
        abort_unless($request->user()->hasRole('Finance') && $request->user()->can('billing.statutory.approve'), 403);
        $data = $request->validate([
            'finance_approval_reference' => ['required', 'string', 'min:4', 'max:160'],
            'legal_approval_reference' => ['required', 'string', 'min:4', 'max:160'],
            'confirmation' => ['required', 'in:finance_and_legal_approved'],
        ]);
        $row = DB::transaction(function () use ($request, $version, $data): object {
            $profile = DB::table('billing_issuance_profiles')->where('version', $version)->lockForUpdate()->firstOrFail();
            abort_unless($profile->status === 'pending_approval', 409);
            abort_unless((int) $profile->submitted_by !== (int) $request->user()->id, 403, 'The proposer cannot approve their own statutory profile.');
            DB::table('billing_issuance_profiles')->where('status', 'approved')->whereNull('superseded_at')->update([
                'superseded_at' => $profile->effective_from, 'updated_at' => now(),
            ]);
            DB::table('billing_issuance_profiles')->where('id', $profile->id)->update([
                'status' => 'approved', 'approved_by' => $request->user()->id,
                'finance_approval_reference' => $data['finance_approval_reference'],
                'legal_approval_reference' => $data['legal_approval_reference'], 'approved_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('billing_issuance_profiles')->where('id', $profile->id)->first();
        });
        $audit->record('billing.statutory_profile.approved', null, ['profile_id' => $row->id, 'version' => $row->version, 'finance_reference' => $row->finance_approval_reference, 'legal_reference' => $row->legal_approval_reference]);

        return response()->json(['data' => $row, 'message' => 'Approval references recorded. An approved profile alone does not enable statutory issuance.']);
    }
}
