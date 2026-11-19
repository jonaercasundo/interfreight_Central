<?php

namespace Tests\Feature;

use App\Services\FinancialPolicyEngine;
use Tests\TestCase;

class FinancialPolicyEngineTest extends TestCase
{
    public function test_unapproved_or_incomplete_financial_basis_keeps_profitability_unknown(): void
    {
        $result = app(FinancialPolicyEngine::class)->reconcile('100.00', 'PHP', '60.00', 'PHP', 'PHP', [
            'status' => 'draft', 'approval_reference' => null, 'approved_by' => null,
            'revenue_recognition_source' => null, 'final_cost_accrual_method' => null, 'fx_source' => null,
        ], '100.00', '40.00');

        $this->assertSame('not_determinable', $result['status']);
        $this->assertNull($result['recognized_revenue']);
        $this->assertNull($result['gross_profit']);
        $this->assertSame('100.00', $result['invoice_amount']);
        $this->assertSame('40.00', $result['collected_cash']);
    }

    public function test_synthetic_financial_reconciliation_requires_approved_policy_and_uses_distinct_sources(): void
    {
        $policy = ['status' => 'approved', 'approval_reference' => 'SYNTHETIC-FINANCE-001', 'approved_by' => 'synthetic-fixture',
            'revenue_recognition_source' => 'synthetic-performance-event', 'final_cost_accrual_method' => 'synthetic-verified-ledger', 'fx_source' => 'synthetic-rate-feed'];
        $result = app(FinancialPolicyEngine::class)->reconcile('120.00', 'USD', '150.00', 'USD', 'USD', $policy, '110.00', '50.00');

        $this->assertSame('reconciled', $result['status']);
        $this->assertSame('120.00', $result['recognized_revenue']);
        $this->assertSame('150.00', $result['final_cost']);
        $this->assertSame('-30.00', $result['gross_profit']);
        $this->assertSame('110.00', $result['invoice_amount']);
        $this->assertSame('50.00', $result['collected_cash']);
    }

    public function test_cross_currency_reconciliation_requires_the_approved_dated_rate_source(): void
    {
        $policy = ['status' => 'approved', 'approval_reference' => 'SYNTHETIC-FINANCE-002', 'approved_by' => 'synthetic-fixture',
            'revenue_recognition_source' => 'synthetic-performance-event', 'final_cost_accrual_method' => 'synthetic-verified-ledger', 'fx_source' => 'approved-synthetic-fx', 'fx_pairs' => ['USD/PHP']];
        $result = app(FinancialPolicyEngine::class)->reconcile('100.00', 'USD', '70.00', 'PHP', 'PHP', $policy, null, null, '56.25000000', 'approved-synthetic-fx', '2026-10-23');

        $this->assertSame('reconciled', $result['status']);
        $this->assertSame('5625.00', $result['recognized_revenue']);
        $this->assertSame('70.00', $result['final_cost']);
        $this->assertSame('5555.00', $result['gross_profit']);
    }

    public function test_cross_currency_reconciliation_remains_unknown_without_an_approved_currency_pair(): void
    {
        $policy = ['status' => 'approved', 'approval_reference' => 'SYNTHETIC-FINANCE-003', 'approved_by' => 'synthetic-fixture',
            'revenue_recognition_source' => 'synthetic-performance-event', 'final_cost_accrual_method' => 'synthetic-verified-ledger', 'fx_source' => 'approved-synthetic-fx', 'fx_pairs' => []];
        $result = app(FinancialPolicyEngine::class)->reconcile('100.00', 'USD', '70.00', 'PHP', 'PHP', $policy, null, null, '56.25', 'approved-synthetic-fx', '2026-10-23');

        $this->assertSame('not_determinable', $result['status']);
        $this->assertNull($result['gross_profit']);
    }
}
