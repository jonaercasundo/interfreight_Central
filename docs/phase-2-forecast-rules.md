# IFC Phase 2 — Forecast Rules

## Meaning

A forecast is expected shipment demand originating from a client inquiry. It is not an operational shipment, booking, quotation, budget, expense, invoice, or financial commitment. Forecast counts represent the expected shipment quantity for the inquiry during the selected month.

## Confidence and Accounting visibility

- `tentative`: early planning; excluded from the Accounting view.
- `probable`: planning expectation; included in the read-only Accounting view.
- `confirmed`: forecast with stronger client intent; included in the read-only Accounting view.

Only active probable and confirmed forecast rows appear in Accounting forecast review and monthly aggregation. Withdrawn forecasts remain available to Sales history but do not count in active demand.

## Month and uniqueness

`forecast_month` must be the first calendar day of a month and cannot precede the current month. The unique inquiry/month constraint allows a multi-month plan but prevents accidental duplicate rows for one inquiry and period. Updates increment the forecast revision and append a before/after revision row; withdrawing does the same.

## Cost and cash advance fields

No amounts or fee categories are collected until approved source data and calculation rules exist. Accounting responses explicitly carry null shipping-fee and cash-advance amounts and `cost_estimate_status: not_estimated`. Do not infer charges from client type, mode, route, shipment count, or prior Phase 1 shipment costs.

## Inquiry progression

`draft` → `submitted` → `under_review` → `forecasted` is the normal progression. A submitted, under-review or forecasted inquiry may be withdrawn or cancelled. `draft` can be cancelled by an authorized owner. Invalid transitions are rejected; status changes are audited. Future quotation, client-approval, operations and shipment lifecycle steps require separate approved policies and records.
