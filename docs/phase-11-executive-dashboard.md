# Phase 11 Executive Dashboard

## Delivered

`GET /executive` is the Executive Command Center inside the shared IFC shell. Executive accounts are sent here after login. The route and its data API require `executive.overview.view`; the controller also requires the Executive role, so Administrator does not receive enterprise financial data merely because its system role has broad administration permissions.

`GET /api/executive/dashboard?month=YYYY-MM` returns source-backed shipment, budget, expense, invoicing, collections, receivable, exception, and department-assignment data. The month is validated and returned with the application timezone, refresh time, currency scope, and metric definitions. Company shipment counts use distinct master shipment IDs. Department assignment counts are explicitly labeled work items rather than shipment totals.

## Financial limits

Amounts are grouped by their source currency. There is no approved enterprise FX conversion policy. Approved budget is the latest approved version per month, department and currency. Expenses include only verified actual expense rows. Collections include only reconciled receipts. Receivables use issued invoices less reconciled allocations.

Authorized commercial revenue, recognized revenue, and gross profit are shown as unavailable because IFC has no approved revenue-recognition source and complete final-cost basis. Invoice issuance and cash collection are not treated as revenue. Company nonrecoverable expense records are labeled as such and are not asserted to be final absorbed losses.

## Not yet delivered

Drill-down pages for the executive analytics menu, client/service/direction segment analysis, executive reports and PDF exports, detailed departmental service-level measures, and configurable comparison periods beyond the previous month remain future work. No links to these unavailable pages are exposed.
