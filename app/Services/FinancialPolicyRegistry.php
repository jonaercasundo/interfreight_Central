<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FinancialPolicyRegistry
{
    public function active(): ?object
    {
        return DB::table('financial_policy_versions')->where('status', 'approved')
            ->whereNotNull('effective_from')->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('superseded_at')->orWhere('superseded_at', '>', now()))
            ->orderByDesc('version')->first();
    }

    public function enginePolicy(): array
    {
        $row = $this->active();
        if (! $row) {
            return ['status' => 'unconfigured'];
        }
        $config = json_decode($row->policy, true) ?: [];

        return [
            'status' => 'approved',
            'approval_reference' => $row->approval_reference,
            'approved_by' => $row->approved_by,
            'policy_version_id' => $row->id,
            'policy_version' => $row->version,
            'revenue_recognition_source' => $config['revenue_recognition']['source'] ?? null,
            'revenue_recognition_rule' => $config['revenue_recognition']['rule'] ?? null,
            'final_cost_accrual_source' => $config['final_cost_accrual']['source'] ?? null,
            'final_cost_accrual_method' => $config['final_cost_accrual']['method'] ?? null,
            'reporting_currency' => $config['reporting_currency'] ?? null,
            'fx_source' => $config['fx']['source'] ?? null,
            'fx_pairs' => $config['fx']['pairs'] ?? [],
        ];
    }
}
