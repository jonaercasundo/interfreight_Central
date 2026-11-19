# Phase 3 Financial Formulas

All submitted decimal inputs are validated as strings with explicit scale. `CommercialCalculator` converts inputs to scaled integers and uses integer division with half-up rounding; no binary float is used for application financial calculations. Persisted monetary amounts use decimal columns with two fractional places.

For quantity `q` and unit rate `u`, line gross is `round_half_up(q × u, 2 currency decimals)`. Tax exclusive adds `round_half_up(taxable × rate, 2)`; tax inclusive derives the pre-tax amount as `round_half_up(gross / (1 + rate), 2)`; no-tax leaves gross unchanged. Foreign currency line totals are converted at the validated exchange rate and rounded half up to the estimate currency's minor unit.

Quotation line subtotal is `round_half_up(quantity × unit price, 2)`. Discounts are either zero, a percentage of subtotal (0–100%), or a fixed amount. A discount greater than the line subtotal is rejected. Tax is applied after the discount using the line tax treatment. Quote subtotal, discount, tax, and total are sums of calculated line amounts.

`Gross Profit = Selling Price - Total Estimated Cost`

`Gross Margin % = Gross Profit / Selling Price × 100`

`Markup % = Gross Profit / Total Estimated Cost × 100`

A zero selling price has an undefined margin; zero cost has an undefined markup. Negative profit produces a loss warning. Configured low/minimum margin values are read from `IFC_LOW_MARGIN_PERCENT` and `IFC_MINIMUM_MARGIN_PERCENT`; unset thresholds do not invent a policy. For target fraction `M`, where `0 ≤ M < 1`, required price is `Total Estimated Cost / (1 - M)`.

Funding required is the sum of cost lines flagged as requiring funding. It is not added to estimated expense total unless it is independently represented as an expense line. Cash advance is therefore a funding classification, not a second expense.
