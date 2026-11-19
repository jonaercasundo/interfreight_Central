# IFC Phase 2 — Data Model

The Phase 2 migration is additive and does not rewrite Phase 1 shipment or identity records.

## `clients`

Stores client contact and service category details. `sales_owner_id` references the owning user with delete restriction. `reference` is unique and assigned after insert as `CLI-<year>-<zero-padded id>`. Client types are `LCL`, `FCL`, and `Mixed`.

## `client_inquiries`

References a client and owner; captures service (`LCL`/`FCL`), transport mode, origin, destination, cargo description, optional weight/volume/container/commodity/special requirements/requested date, status and revision. Unique reference format is `INQ-<year>-<zero-padded id>`. Current statuses: `draft`, `submitted`, `under_review`, `forecasted`, `withdrawn`, `cancelled`.

## `shipment_forecasts`

References an inquiry and owner. Each row represents expected demand for one inquiry in one month. Fields include first-of-month `forecast_month`, positive `expected_shipment_count`, confidence (`tentative`, `probable`, `confirmed`), status (`active`, `withdrawn`), and a revision counter. A unique `(inquiry_id, forecast_month)` constraint prevents duplicate monthly rows; a single inquiry can span many months.

## `forecast_revisions`

Append-only application history for created, updated and withdrawn forecasts. Captures forecast, actor, monotonic revision, action and before/after values, with timestamps. The existing `audit_logs` table also records a human-readable event and the actor/target for each change.

## Relationships and deletion

`Client hasMany ClientInquiry`; `ClientInquiry belongsTo Client` and `hasMany ShipmentForecast`; `ShipmentForecast belongsTo ClientInquiry` and `hasMany ForecastRevision`. Client, inquiry and forecast foreign keys restrict destructive deletion. The application exposes no delete endpoint.

## Query behavior

Sales list endpoints filter by `sales_owner_id`. Inquiry lists eager-load the client and owner. Forecast lists eager-load inquiry/client and owner. Accounting queries eager-load inquiry/client and return only authorized forecast fields, while monthly totals use a grouped aggregate query. History loads the actor and forecast in bounded pages.
