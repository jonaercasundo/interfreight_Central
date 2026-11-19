# IFC Phase 2 — Sales and Forecast Requirements

## Scope

Phase 2 adds Sales client management, client inquiry capture, shipment demand forecasts, a monthly planning view, and read-only forecast review for Accounting. It uses the existing Laravel 13, Vue 3, Blade-mounted application shell, same-origin session authentication, CSRF protection, and Spatie permissions. The implementation does not create operational shipments, quotations, costs, approvals, invoices, payments, or profitability records.

## Sales workflow

1. A Sales representative creates a client profile and becomes its owner.
2. The same representative records an inquiry with client, service type, mode, origin, destination, cargo, and optional LCL/FCL details.
3. An inquiry starts in `draft`; Sales can edit the draft and submit it.
4. The owner may progress submitted work into review, forecasted, withdrawn, or cancelled using the permitted state transitions.
5. A reviewed inquiry can have one forecast row per calendar month. A forecast includes an expected shipment count and confidence (`tentative`, `probable`, `confirmed`). It may be revised or withdrawn; both actions retain revision history.

## Data integrity and limits

- Clients and inquiries are sales-owned; list and record operations are scoped to the authenticated owner.
- Client and inquiry references are generated from the inserted database ID within a transaction, so concurrent creates do not depend on a random reference.
- One inquiry may have a forecast in many months. The database enforces at most one forecast row for an inquiry and month.
- Forecast months use the first day of the month. Expected shipment count is a positive integer.
- `LCL`/`FCL` describe the requested service. Transport mode is `Sea`, `Air`, `Road`, or `Rail`.
- Required ship date cannot be in the past. Weight and volume are estimates supplied by Sales and are not actual shipment measurements.
- Invalid state transitions return a conflict. Unauthorized actions are denied server-side.
- Deletion is intentionally omitted; foreign keys restrict deletion of clients, inquiries and forecasts with dependent records.

## Separation from execution and finance

Forecast demand is a planning record, not a shipment record. There is no automatic conversion or shared shipment number at this stage. Expense estimates, actual expenses, cash advance amounts, shipping-fee amounts, quotation pricing, client approvals, billing and collection are not captured by this slice. Accounting receives `null` amounts and an explicit `not_estimated` marker so the interface does not imply invented financial values.

## Main entry points

- `/sales` Sales overview
- `/sales/clients` Client management
- `/sales/inquiries` Inquiry capture and status workflow
- `/sales/forecasts/calendar` Monthly forecast cards
- `/sales/forecasts/monthly` Monthly demand summary
- `/sales/forecasts/history` Revision history
- `/accounting/forecasts` read-only probable/confirmed forecast review
- `/api/sales/*` Sales API; `/api/accounting/forecasts*` Accounting read-only API
