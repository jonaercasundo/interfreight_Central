# Client Inquiry to Shipment Forecast workflow

## Previous workflow

Submitting an inquiry set it to `submitted`, but forecast creation required `under_review` or `forecasted`. A Sales Manager approval queue or a separate status action moved it to `under_review`. Dashboard cards and assistant guidance described this as a review gate.

## Corrected workflow

An authorized Sales employee creates a `draft`, reviews required client, service, route and cargo details, and submits it directly to `submitted`. The submission records `submitted_at` and `submitted_by`, increments the revision and writes an audit event. No Client Inquiry approval task is created. A submitted inquiry is immediately eligible for a shipment forecast. Its first active forecast changes it to `forecasted`; withdrawing the last active forecast returns it to `submitted`. Forecast revisions and audit entries remain intact.

The previous `under_review` value remains readable and forecast-eligible for historical records, but the API no longer creates that state through an inquiry approval action. Existing withdrawn and cancelled inquiry handling remains available to authorized users. The database change is additive and nullable, so historical inquiries retain their status and need no backfill.

## Forecast and assistant integration

The Client Inquiries table now offers **Add forecast** for submitted inquiries. Forecast Calendar can open a preselected inquiry using its authorized ID lookup. The assistant offers **Create Shipment Forecast** after submission, loads the saved inquiry, reuses client, route, service, mode, cargo and requested ship date, asks for missing month, count and confidence one at a time, shows a preview with correction controls, and saves only on explicit confirmation. Laravel still enforces ownership, permissions, valid values and the existing unique inquiry/month constraint; multiple months remain supported. English, Filipino and Taglish prompts are selected from recent user messages.

In-page action invalidation refreshes Client Inquiries, Forecast Calendar, Monthly Demand, Sales Dashboard and authorized Accounting forecast views. Current filters and pagination are retained; a new record outside the current filter may not appear until the filter changes. The assistant conversation stays open during forecast creation.

## Department responsibilities

| Department | Next responsibility |
| --- | --- |
| Sales | Client inquiry, forecast, client coordination and quotation preparation |
| Operations | Operational requirements, customs or brokerage estimates and expenses |
| Transport | Trucking requirements, delivery planning and transport estimates |
| Forwarding | Carrier, air or sea booking and freight estimates |
| Accounting | Read-only probable or confirmed forecast demand, budget and cash planning; estimate review under existing controls |
| Authorized quotation approver | Quotation review and approval under the configured policy |

No departmental approval is required to submit a Client Inquiry. Forecasts are planning records, not approved expenditures or operational shipments. Quotation approval, client acceptance, shipment authorization and financial controls were retained.

## Checks

- Laravel targeted feature suites: 38 passed, 528 assertions, including inquiry lifecycle, assistant guidance, accounting scope, quotation approval and billing regression.
- Frontend unit suite: 17 passed after adding forecast invalidation coverage.
- Python AI suite: 33 passed. One dependency deprecation warning.
- Vite production build: passed. It reports an existing bundle size advisory.
- Applied only the additive submission metadata migration to the local SQLite database. Another unrelated assistant form migration remains pending; it was not run.

## Remaining limitations

- The assistant forecast wizard accepts values through the chat composer or the in-panel fields. Date and quantity answers must use the form's exact month and numeric formats; free-form natural-language dates are not parsed.
- The assistant forecast wizard has build coverage and backend lifecycle tests, but no browser end-to-end test of its interactive steps was run.
- Forecast demand for Accounting remains read-only and restricted to active probable or confirmed forecasts. No estimated expense or cash advance amount is inferred where none is recorded.
- Historical `under_review` records remain visible for compatibility; they are not rewritten to `submitted`.
