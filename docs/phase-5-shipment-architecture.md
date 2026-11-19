# Phase 5 Shipment Workspace Architecture

The shipment workspace is the shared operational register for Phase 5. Laravel web-session routes under `/api/shipments-workspace` serve the Vue workspace. The same authenticated application shell is used for shipment register, detail, Operations, Transport, and Forwarding pages.

`ShipmentWorkspaceController` owns validated commands and queries. The `Shipment` model is the master record; department assignments and operational records remain separate related models. Query scopes enforce visibility for administrators/executives, read-only Accounting/Finance users, Sales owners, and assigned department users. Mutations also check their specific Spatie permission server-side.

Job numbers use a locked per-year sequence (`IFC-YYYY-NNNNNN`). Creation accepts an idempotency key and runs with its initial assignments, history and timeline in a database transaction. Shipment updates use lock versions and master status changes are recorded separately from operational leg states.

Private shipment files use Laravel's local private disk and authorized controller downloads. No public storage URLs are issued. Existing Phase 1–4 quote, forecast, client and funding records are linked by foreign keys; Phase 5 does not post invoices, payments or actual expenses.

The workspace is an incremental implementation. Notifications, external carrier tracking, configurable checklist administration, and financial close remain future integrations.
