# Phase 4: Financial Formulas

## Funding requirement

For each active probable/confirmed forecast and its current authoritative approved estimate:

`funding requirement = estimate line estimate_currency_total × forecast expected_shipment_count`

Only estimate lines marked `funding_required = true` are included. The estimate currency is retained. No FX conversion is configured, so currencies are never combined. Quotation subtotal/total is client selling price and is excluded.

An estimate without a matching eligible forecast is not included in forecast-month demand. A forecast without an estimate is shown as provisional with a null amount, rather than a zero-cost shipment. When a newer unapproved estimate exists, its funding-required cost lines are multiplied by the forecast count and shown in a separate provisional bucket. The provisional bucket is excluded from approved demand, coverage shortfall, and allocations.

## Budget and allocation

`requested total = exact decimal sum of draft line requested_amount values`

`approved total = requested total copied at approval`

`remaining line authority = approved line amount − existing allocations on the line`

Allocation is accepted only when its positive amount fits both remaining line authority and the remaining amount of its referenced funding requirement. The plan, line, source month, currency and expense category must match. Allocations reserve authorization; they do not assert that cash was spent or paid.

## Variance

`requested vs approved = approved total − requested total`

`approval variance % = (approved − requested) ÷ requested × 100`; if requested is zero, percentage is N/A.

`approved vs allocated = approved total − allocated total`

Each value stays in its budget currency. Commitment vs actual and forecast vs approved comparisons requiring source data not yet present are shown as unavailable.

## Consumption and variance

No formula for consumed budget, open commitment deduction, actual expense, cash balance, or cash shortfall is reported because the source records do not exist. Do not calculate `approved − actual − commitments` until authoritative, reconciled sources are connected and paid amounts are excluded from open commitments. Variance percentages with a zero baseline are N/A. Cross-currency variances are not calculated.
