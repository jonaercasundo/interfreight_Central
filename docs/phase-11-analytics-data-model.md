# Phase 11 Analytics Data Model

The current dashboard uses live read-only aggregate queries over authoritative IFC sources; it does not copy operational data into a second analytics store.

| Measure | Source and rule |
|---|---|
| Forecast demand | Active `shipment_forecasts`, summed by `forecast_month` and expected units |
| Actual master jobs | Distinct non-archived, non-canceled `shipments`; creation month for KPI, planned departure for comparison series |
| Operational completion | Distinct shipments with Operations completion milestone verified in the selected month |
| Financial closing | Distinct shipments with a closed closing request in the selected month |
| Budget | Latest approved `monthly_budget_plans` version grouped by currency |
| Actual expense | Verified `actual_expenses` grouped by incurred month and currency |
| Invoices and receivables | Issued `billing_invoices`; allocated payments count only when reconciled and not reversed |
| Collections | Reconciled receipts grouped by reconciliation timestamp and currency |
| Department activity | Assignment records by department and status; these are not additive to company shipment counts |

Date bounds use the configured Laravel timezone. Currency conversion is intentionally absent. Forecast accuracy is a prior-month comparison between forecast units and distinct master shipments by planned departure; it is null when no forecast exists. It is not presented as a validated ML score.

AI forecast input is limited to a contiguous monthly series of aggregate distinct master shipment counts. It excludes client names, customer records, shipment identifiers, documents, and unrestricted database access. Each run stores the aggregate input, time range, model version, evaluation, output, generating user, request ID, and timestamp in `ai_prediction_runs`.

Pre-aggregated tables, immutable executive snapshots, and reconciliation jobs are not justified yet by measured data volume and have not been added. Revenue-recognition and FX policies remain required before revenue/profit consolidation.

The prediction-run record also stores the distinct FastAPI service correlation ID, alongside IFC's unique run request ID.
