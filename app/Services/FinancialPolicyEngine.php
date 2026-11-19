<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Computes a shipment reconciliation only when its accounting basis is approved.
 * Invoice values and collected cash are returned as separate facts and never used
 * as a substitute for recognized revenue.
 */
class FinancialPolicyEngine
{
    public function reconcile(
        ?string $recognizedRevenue,
        ?string $revenueCurrency,
        ?string $finalCost,
        ?string $costCurrency,
        string $reportingCurrency,
        array $policy,
        ?string $invoiceAmount = null,
        ?string $collectedCash = null,
        ?string $fxRate = null,
        ?string $fxSource = null,
        ?string $fxAsOf = null,
    ): array {
        $blockers = [];
        $approved = ($policy['status'] ?? null) === 'approved'
            && filled($policy['approval_reference'] ?? null)
            && filled($policy['approved_by'] ?? null)
            && filled($policy['revenue_recognition_source'] ?? null)
            && filled($policy['final_cost_accrual_method'] ?? null)
            && filled($policy['fx_source'] ?? null);

        if (! $approved) {
            $blockers[] = 'Finance-approved recognition, accrual and FX policies are not configured.';
        }
        if ($recognizedRevenue === null || $revenueCurrency === null) {
            $blockers[] = 'Authoritative recognized revenue is unavailable.';
        }
        if ($finalCost === null || $costCurrency === null) {
            $blockers[] = 'Final accrued cost is unavailable.';
        }

        $revenue = $recognizedRevenue;
        $cost = $finalCost;
        if ($revenueCurrency && $revenueCurrency !== $reportingCurrency) {
            $converted = $this->convert($recognizedRevenue, $revenueCurrency, $reportingCurrency, $fxRate, $fxSource, $fxAsOf, $policy);
            $revenue = $converted;
            if ($converted === null) {
                $blockers[] = 'Revenue currency conversion lacks an approved, dated FX rate.';
            }
        }
        if ($costCurrency && $costCurrency !== $reportingCurrency) {
            $converted = $this->convert($finalCost, $costCurrency, $reportingCurrency, $fxRate, $fxSource, $fxAsOf, $policy);
            $cost = $converted;
            if ($converted === null) {
                $blockers[] = 'Cost currency conversion lacks an approved, dated FX rate.';
            }
        }

        $ready = $approved && $revenue !== null && $cost !== null && $blockers === [];
        $profit = $ready
            ? (string) BigDecimal::of($revenue)->minus($cost)->toScale(2, RoundingMode::HalfUp)
            : null;
        $margin = $ready && BigDecimal::of($revenue)->isGreaterThan('0')
            ? (string) BigDecimal::of($profit)->dividedBy($revenue, 6, RoundingMode::HalfUp)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp)
            : null;

        return [
            'status' => $ready ? 'reconciled' : 'not_determinable',
            'currency' => $reportingCurrency,
            'recognized_revenue' => $ready ? $revenue : null,
            'final_cost' => $ready ? $cost : null,
            'gross_profit' => $profit,
            'gross_margin_percent' => $margin,
            'invoice_amount' => $invoiceAmount,
            'collected_cash' => $collectedCash,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function convert(?string $amount, string $source, string $target, ?string $rate, ?string $sourceName, ?string $asOf, array $policy): ?string
    {
        $pair = $source.'/'.$target;
        if ($amount === null || ! $rate || ! preg_match('/^\d+(?:\.\d{1,12})?$/', $rate) || ! BigDecimal::of($rate)->isGreaterThan('0')
            || ! $asOf || ! filled($sourceName) || $sourceName !== ($policy['fx_source'] ?? null)
            || ! in_array($pair, $policy['fx_pairs'] ?? [], true)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            return null;
        }

        return (string) BigDecimal::of($amount)->multipliedBy(BigDecimal::of($rate))->toScale(2, RoundingMode::HalfUp);
    }
}
