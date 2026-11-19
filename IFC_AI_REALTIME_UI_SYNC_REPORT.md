# IFC AI Real-Time UI Sync Report

## Root cause

AI form saves committed correctly, but the global Vue assistant and the independently mounted sales pages did not share a data invalidation signal. The Sales Inquiry table and dashboard only fetched data on mount or through their own manual interactions, so they continued to display stale responses after the assistant saved a draft.

This application renders its page shell with Blade and mounts Vue workspaces that fetch Laravel JSON APIs. It does not use Inertia page props for these workspaces. A repository search found no Laravel broadcasting channels, `ShouldBroadcast` events, or Echo client to reuse.

## Event and refresh architecture

1. `AssistantFormSessionController::saveDraft()` creates the inquiry (and, when requested, a new client) inside the existing database transaction.
2. Only after the transaction commits, the endpoint returns `action_results` with actual saved IDs, references, statuses, success state, and a correlation UUID. Transaction failures return no success results. New-client actions are included only when the transaction created that client.
3. The global assistant publishes supported results through `businessActionSync.js`. Same-page Vue views receive a custom event; other tabs/pages receive a `localStorage` event. Pending module invalidations remain stored until the corresponding data fetch succeeds.
4. Subscribers retrieve current server data through their existing API calls. Refreshes do not reload the browser or insert optimistic rows. Inquiry filters and page selection remain in component state; an inquiry outside the current filter/page is reported instead of forced into the table.
5. A visit to Sales Inquiry Management reads its pending invalidation and reloads the list. The assistant-open state and conversation ID remain in session storage, so the assistant resumes after navigation. The **View Inquiry** action searches by reference and the **Create Another Inquiry** action starts a new conversation/workflow.

Supported action-to-module invalidations are declared centrally for inquiry, client, shipment, and expense draft actions. The assistant currently emits inquiry creation and optional client creation results; other action types will only trigger refreshes once a backend operation returns a matching successful result.

## Routes affected

- `POST /api/assistant/form-sessions/{session}/draft` — returns post-commit structured action results.
- `GET /api/sales/inquiries` — refreshed list, respecting search, status, and pagination parameters.
- `GET /api/sales/dashboard/summary` — refreshed owner-scoped inquiry status counts and existing KPIs.
- `GET /api/sales/clients` — refreshed when the assistant created a client.
- Existing forecast and commercial inquiry APIs are re-read where their pages hold inquiry selectors.

All read endpoints retain their existing authorization and owner scoping. No cross-user event or record data is broadcast.

## Sales Inquiry synchronization verification

- The save response includes `sales_inquiry.created`, `entity_type`, saved `entity_id`, actual reference, `draft` status, `operation_status: success`, and `correlation_id`.
- A newly created client returns its own `client.created` result; existing clients do not create a redundant client event.
- Inquiry list refreshes are fetched from Laravel and keyed by the actual row ID. Filter and page state are preserved; the UI explains when the new reference is outside the current view.
- Failed saves do not publish an event. Failed refreshes remain pending for the next visit.
- An active Sales Inquiry page refreshes without interrupting the assistant conversation.

## Dashboard synchronization verification

The dashboard is invalidated by inquiry/client results and requests its summary again. Laravel calculates counts from inquiries owned by the authenticated user: total, draft, submitted, under review, and approved. IFC represents the approved inquiry state as `forecasted`; the existing pending-approval count remains the submitted count. The frontend does not increment values optimistically.

## Files changed

- `app/Http/Controllers/Api/AssistantFormSessionController.php`
- `app/Http/Controllers/SalesDashboardController.php`
- `resources/js/businessActionSync.js`
- `resources/js/App.vue`
- `resources/js/components/SalesWorkspace.vue`
- `resources/js/components/SalesDashboard.vue`
- `resources/js/components/CommercialWorkspace.vue`
- `resources/js/components/ShipmentWorkspace.vue`
- `resources/js/components/ExpenseWorkspace.vue`
- `tests/Feature/AssistantFormSessionTest.php`
- `tests/Feature/SalesDashboardTest.php`
- `tests/Frontend/businessActionSync.test.js`
- `package.json`

## Verification

- Laravel focused suite: **13 passed, 150 assertions** (`AssistantFormSessionTest` and `SalesDashboardTest`).
- Vue/frontend suite: **16 passed** (`npm run test:frontend`).
- Python suite: **33 passed**, with one upstream Starlette/httpx deprecation warning.
- PHP Pint: passed for changed PHP files.
- Vite production build: passed. Vite reports the existing app JavaScript chunk is over 500 kB minified; this is a bundle-size warning, not a build failure.

## Remaining limitations

- Other users' open pages do not receive live updates because no broadcasting/WebSocket infrastructure is configured. Same-user tabs and page visits synchronize through browser storage.
- Refreshes require an active IFC page in the same browser profile and signed-in user. Cross-device updates are not provided.
- Status counts follow IFC's present workflow vocabulary: `forecasted` is the approved state; there is no separate `approved` inquiry status.
- Browser-level end-to-end tests were not run. Automated coverage includes backend ownership/action-result tests and frontend invalidation/storage tests; pagination/filter retention is implemented in the Vue request state but was not exercised in a mounted-browser test.
