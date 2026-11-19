# Phase 10: Profitability formulas and safeguards

Monetary source sums are converted to integer cents before aggregation. Totals remain segregated by currency. Invoices are reported as invoiced amounts; only reconciled allocations count as customer cash. Cash is never added to revenue.

The current source records do not identify a verified accounting recognition event, a final cost accrual policy, or a complete FX basis. Therefore recognized revenue, gross profit, margin, projected profit, and profitability class are returned as unknown (`null` / Not Yet Determinable), not inferred from quotations, issued invoices, or collections. Verified actual cost is shown only when all included verified costs use one currency.

Once policy and recognition source are implemented, the required equations are `Gross Profit = Recognized Revenue - Final Direct and Allocated Cost` and `Gross Margin = Gross Profit / Recognized Revenue × 100`; zero revenue yields N/A. Company-absorbed classification is memorandum-only and must reference the existing actual expense to avoid double counting.
