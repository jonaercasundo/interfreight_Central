<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Integer-scaled commercial arithmetic. Money and rates are never calculated as binary floats. */
class CommercialCalculator
{
    private const MAX_QUANTITY_SCALED = 1_000_000_000; // 100,000.0000

    private const MAX_UNIT_SCALED = 9_000_000_000; // 900,000.0000

    public const COST_CATEGORIES = [
        'shipping_line', 'ocean_air_freight', 'port_terminal', 'trucking_transport', 'forwarding', 'customs_processing',
        'duties_taxes', 'documentation', 'warehousing', 'handling_labor', 'insurance', 'cash_advance_cost',
        'miscellaneous', 'contingency', 'other',
    ];

    public function costLine(array $line, string $estimateCurrency): array
    {
        $quantity = $this->scaled($line['quantity'] ?? null, 4, 'quantity');
        $unitCost = $this->scaled($line['unit_cost'] ?? null, 4, 'unit_cost');
        if ($quantity <= 0 || $quantity > self::MAX_QUANTITY_SCALED || $unitCost < 0 || $unitCost > self::MAX_UNIT_SCALED) {
            throw ValidationException::withMessages(['lines' => 'Quantity or unit cost is outside the supported financial range.']);
        }
        $currency = strtoupper((string) ($line['currency'] ?? $estimateCurrency));
        if ($currency === $estimateCurrency) {
            $rate = 100_000_000;
            $rateString = '1.00000000';
        } else {
            if (empty($line['exchange_rate_to_estimate'])) {
                throw ValidationException::withMessages(['lines' => "An exchange rate is required to convert {$currency} to {$estimateCurrency}."]);
            }
            $rate = $this->scaled($line['exchange_rate_to_estimate'], 8, 'exchange_rate_to_estimate');
            if ($rate <= 0) {
                throw ValidationException::withMessages(['lines' => ['Exchange rates must be greater than zero.']]);
            }
            $rateString = $this->decimalString($rate, 8);
        }

        $gross = $this->roundRatio($quantity * $unitCost, 1_000_000);
        $taxRate = $this->scaled($line['tax_rate'] ?? '0', 4, 'tax_rate');
        if ($taxRate < 0 || $taxRate > 1_000_000) {
            throw ValidationException::withMessages(['lines' => ['Tax rate must be between 0 and 100 percent.']]);
        }
        $treatment = $line['tax_treatment'] ?? 'none';
        [$subtotal, $tax, $total] = $this->taxAmounts($gross, $taxRate, $treatment);
        $converted = $this->roundProductRatio($total, $rate, 100_000_000);

        return [
            'category' => $line['category'], 'description' => $line['description'], 'supplier' => $line['supplier'] ?? null,
            'quantity' => $this->decimalString($quantity, 4), 'unit' => $line['unit'], 'unit_cost' => $this->decimalString($unitCost, 4),
            'currency' => $currency, 'exchange_rate_to_estimate' => $rateString, 'exchange_rate_source' => $line['exchange_rate_source'] ?? null,
            'exchange_rate_date' => $line['exchange_rate_date'] ?? null, 'tax_treatment' => $treatment,
            'tax_rate' => $this->decimalString($taxRate, 4), 'subtotal' => $this->cents($subtotal), 'tax_amount' => $this->cents($tax),
            'line_total' => $this->cents($total), 'estimate_currency_total' => $this->cents($converted),
            'cost_type' => $line['cost_type'] ?? 'direct', 'cost_payer' => $line['cost_payer'] ?? 'company',
            'recoverability' => $line['recoverability'] ?? 'nonrecoverable', 'funding_required' => (bool) ($line['funding_required'] ?? false),
            'notes' => $line['notes'] ?? null,
            '_estimate_cents' => $converted,
        ];
    }

    public function summarizeCosts(array $lines): array
    {
        $totals = ['direct' => 0, 'indirect' => 0, 'contingency' => 0, 'funding' => 0, 'recoverable' => 0, 'nonrecoverable' => 0];
        foreach ($lines as $line) {
            $amount = $line['_estimate_cents'];
            if ($line['category'] === 'contingency') {
                $totals['contingency'] += $amount;
            } elseif ($line['cost_type'] === 'indirect') {
                $totals['indirect'] += $amount;
            } else {
                $totals['direct'] += $amount;
            }
            if ($line['funding_required']) {
                $totals['funding'] += $amount;
            }
            $totals[$line['recoverability']] += $amount;
        }

        if (max($totals['direct'] + $totals['indirect'] + $totals['contingency'], $totals['funding'], $totals['recoverable'], $totals['nonrecoverable']) > 99_999_999_999_999) {
            throw ValidationException::withMessages(['lines' => 'Cost estimate exceeds the supported currency range.']);
        }

        return [
            'direct_cost_total' => $this->cents($totals['direct']), 'indirect_cost_total' => $this->cents($totals['indirect']),
            'contingency_total' => $this->cents($totals['contingency']),
            'estimated_cost_total' => $this->cents($totals['direct'] + $totals['indirect'] + $totals['contingency']),
            'funding_required_total' => $this->cents($totals['funding']), 'recoverable_total' => $this->cents($totals['recoverable']),
            'nonrecoverable_total' => $this->cents($totals['nonrecoverable']),
        ];
    }

    public function quotationLine(array $line): array
    {
        $quantity = $this->scaled($line['quantity'] ?? null, 4, 'quantity');
        $unitPrice = $this->scaled($line['unit_price'] ?? null, 4, 'unit_price');
        if ($quantity <= 0 || $quantity > self::MAX_QUANTITY_SCALED || $unitPrice < 0 || $unitPrice > self::MAX_UNIT_SCALED) {
            throw ValidationException::withMessages(['lines' => 'Quantity or unit price is outside the supported financial range.']);
        }
        $subtotal = $this->roundRatio($quantity * $unitPrice, 1_000_000);
        $discountType = $line['discount_type'] ?? 'none';
        $discountValue = $this->scaled($line['discount_value'] ?? '0', 4, 'discount_value');
        $discount = match ($discountType) {
            'none' => 0,
            'percent' => $discountValue <= 1_000_000 ? $this->roundRatio($subtotal * $discountValue, 1_000_000) : throw ValidationException::withMessages(['lines' => ['Discount percentage cannot exceed 100%.']]),
            'amount' => $this->roundRatio($discountValue, 100),
            default => throw ValidationException::withMessages(['lines' => ['Invalid discount type.']]),
        };
        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['lines' => ['A line discount cannot exceed its selling value.']]);
        }
        $taxRate = $this->scaled($line['tax_rate'] ?? '0', 4, 'tax_rate');
        [$taxable, $tax, $total] = $this->taxAmounts($subtotal - $discount, $taxRate, $line['tax_treatment'] ?? 'none');

        return [
            'description' => $line['description'], 'quantity' => $this->decimalString($quantity, 4), 'unit' => $line['unit'],
            'unit_price' => $this->decimalString($unitPrice, 4), 'discount_type' => $discountType,
            'discount_value' => $this->decimalString($discountValue, 4), 'tax_treatment' => $line['tax_treatment'] ?? 'none',
            'tax_rate' => $this->decimalString($taxRate, 4), 'subtotal' => $this->cents($subtotal),
            'discount_amount' => $this->cents($discount), 'tax_amount' => $this->cents($tax), 'line_total' => $this->cents($taxable + $tax),
            '_subtotal_cents' => $subtotal, '_discount_cents' => $discount, '_tax_cents' => $tax, '_total_cents' => $taxable + $tax,
        ];
    }

    public function quotationTotals(array $lines): array
    {
        $calculated = array_map(fn (array $line) => $this->quotationLine($line), $lines);
        $subtotal = array_sum(array_column($calculated, '_subtotal_cents'));
        $discount = array_sum(array_column($calculated, '_discount_cents'));
        $tax = array_sum(array_column($calculated, '_tax_cents'));
        $total = array_sum(array_column($calculated, '_total_cents'));
        if ($total > 99_999_999_999_999) {
            throw ValidationException::withMessages(['lines' => 'Quotation total exceeds the supported currency range.']);
        }

        return ['lines' => $calculated, 'subtotal' => $this->cents($subtotal), 'discount_total' => $this->cents($discount), 'tax_total' => $this->cents($tax), 'total' => $this->cents($total), '_total_cents' => $total];
    }

    public function margin(string $cost, string $sellingPrice, ?string $targetMarginPercent = null): array
    {
        $costCents = $this->moneyCents($cost, 'cost');
        $priceCents = $this->moneyCents($sellingPrice, 'selling_price');
        $profit = $priceCents - $costCents;
        $margin = $priceCents === 0 ? null : $this->ratioPercent($profit, $priceCents);
        $markup = $costCents === 0 ? null : $this->ratioPercent($profit, $costCents);
        $low = config('ifc_finance.low_margin_percent');
        $minimum = config('ifc_finance.minimum_margin_percent');
        $target = $targetMarginPercent ?? $minimum;
        $lowScaled = $low === null || $low === '' ? null : $this->scaled((string) $low, 4, 'low_margin_percent');
        $minimumScaled = $minimum === null || $minimum === '' ? null : $this->scaled((string) $minimum, 4, 'minimum_margin_percent');
        $targetScaled = $target === null || $target === '' ? null : $this->scaled((string) $target, 4, 'target_margin_percent');
        $policyWarning = $profit < 0 ? 'loss' : ($margin !== null && $minimumScaled !== null && $this->scaled($margin, 4, 'gross_margin_percent') < $minimumScaled ? 'below_minimum_margin' : ($margin !== null && $lowScaled !== null && $this->scaled($margin, 4, 'gross_margin_percent') < $lowScaled ? 'low_margin' : null));

        return [
            'selling_price' => $this->cents($priceCents), 'total_estimated_cost' => $this->cents($costCents),
            'gross_profit' => $this->cents($profit), 'gross_margin_percent' => $margin,
            'markup_percent' => $markup,
            'required_selling_price_at_target_margin' => $targetScaled === null || $targetScaled >= 1_000_000 ? null : $this->requiredSellingPrice($costCents, $targetScaled),
            'warning' => $policyWarning,
        ];
    }

    public function cents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public function moneyCents(string $value, string $field): int
    {
        return $this->scaled($value, 2, $field);
    }

    private function taxAmounts(int $gross, int $taxRate, string $treatment): array
    {
        if ($taxRate < 0 || $taxRate > 1_000_000 || ! in_array($treatment, ['none', 'exclusive', 'inclusive'], true)) {
            throw ValidationException::withMessages(['lines' => ['Invalid tax treatment or tax rate.']]);
        }
        if ($treatment === 'none' || $taxRate === 0) {
            return [$gross, 0, $gross];
        }
        if ($treatment === 'inclusive') {
            $subtotal = $this->roundRatio($gross * 1_000_000, 1_000_000 + $taxRate);

            return [$subtotal, $gross - $subtotal, $gross];
        }
        $tax = $this->roundRatio($gross * $taxRate, 1_000_000);

        return [$gross, $tax, $gross + $tax];
    }

    private function ratioPercent(int $numerator, int $denominator): string
    {
        $negative = $numerator < 0;
        if (intdiv(abs($numerator), $denominator) >= intdiv(PHP_INT_MAX, 1_000_000)) {
            throw ValidationException::withMessages(['lines' => 'The resulting margin or markup percentage exceeds the supported range.']);
        }
        $value = $this->roundProductRatio(abs($numerator), 1_000_000, $denominator);

        return ($negative ? '-' : '').$this->decimalString($value, 4);
    }

    private function requiredSellingPrice(int $costCents, int $targetScaled): ?string
    {
        $denominator = 1_000_000 - $targetScaled;
        $maximumCents = 99_999_999_999_999;
        $maximumQuotient = intdiv($maximumCents, 1_000_000);
        $maximumRemainder = $maximumCents % 1_000_000;
        $maximumAffordableCost = ($maximumQuotient * $denominator) + intdiv($maximumRemainder * $denominator, 1_000_000);
        if ($costCents > $maximumAffordableCost) {
            return null;
        }

        return $this->cents($this->roundProductRatio($costCents, 1_000_000, $denominator));
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw ValidationException::withMessages(['amount' => 'Financial calculation denominator must be positive.']);
        }
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        return $quotient + ($remainder * 2 >= $denominator ? 1 : 0);
    }

    private function roundProductRatio(int $left, int $right, int $denominator): int
    {
        if ($denominator <= 0) {
            throw ValidationException::withMessages(['amount' => 'Financial calculation denominator must be positive.']);
        }
        $rightWhole = intdiv($right, $denominator);
        $rightRemainder = $right % $denominator;
        $base = $left * $rightWhole;
        $fraction = $this->roundRatio(($left % $denominator) * $rightRemainder, $denominator)
            + intdiv($left, $denominator) * $rightRemainder;

        return $base + $fraction;
    }

    private function scaled(mixed $value, int $scale, string $field): int
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([$field => 'Financial values must be provided as decimal strings.']);
        }
        $value = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,'.$scale.'})?$/', $value)) {
            throw ValidationException::withMessages([$field => "Enter a non-negative value with no more than {$scale} decimal places."]);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $wholeLimit = intdiv(PHP_INT_MAX, 10 ** $scale);
        if (strlen(ltrim($whole, '0')) > strlen((string) $wholeLimit) || (int) $whole > $wholeLimit) {
            throw ValidationException::withMessages([$field => 'Financial value exceeds the supported range.']);
        }

        return ((int) $whole * (10 ** $scale)) + (int) str_pad($fraction, $scale, '0');
    }

    private function decimalString(int $value, int $scale): string
    {
        $absolute = abs($value);

        return ($value < 0 ? '-' : '').intdiv($absolute, 10 ** $scale).'.'.str_pad((string) ($absolute % (10 ** $scale)), $scale, '0', STR_PAD_LEFT);
    }
}
