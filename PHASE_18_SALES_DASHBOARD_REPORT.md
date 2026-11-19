# InterFreight Central — Sales Dashboard Implementation Report

**Date:** 2026-10-24 (Asia/Singapore)
**Scope:** Dedicated Sales landing dashboard, shipment calendar, verified availability register, RBAC integration, and focused regression verification.
**Production data/deployment:** None touched; no production deployment performed.

## Root cause of the Sales `/dashboard` 403

The `/dashboard` route was a static `Route::view` protected by `can:dashboard.view`. Laravel rejected a Sales account before it could reach a role-aware landing page when that account did not have `dashboard.view`. A newly seeded Sales role normally receives that shared permission. The observed account's effective permission snapshot was not available to inspect, so whether its grant had been removed or its role cache was stale cannot be determined from the project files. The route's missing Sales-specific landing/fallback was the code defect.

The route now uses `DashboardLandingController`: Administrator stays on the shared dashboard, an authorized Executive lands on `/executive`, Sales users with `sales.dashboard.view` land on `/sales/dashboard`, then users with the shared dashboard permission can use the shared dashboard. A user without any authorized dashboard receives a clear 403. Mixed-role priority is documented and tested: Administrator → Executive → Sales → shared dashboard. Sales receives no Executive permission.

## Implemented

- Added `/sales/dashboard` and `SalesDashboard.vue` in the existing IFC authenticated shell.
- Added real, Sales-owner-scoped summary figures for inquiries, submitted inquiries awaiting approval, forecast units, approved/issued/accepted quotations, and upcoming operational shipments. Forecast counts and shipment counts use separate records and are not combined.
- Added a month, week, and list calendar. It shows owner-visible inquiry forecasts separately from planned shipment departures, confirmed forwarding ETD/ETA, transport trip dates, and shipment milestones. Filters cover client, route, mode, and status. Detail requests recheck owner visibility; inaccessible records return 404.
- Added the shared shipment availability register. It displays route, mode/service, departure, available and reserved/confirmed capacity, units, cutoff, current status, source, verifier, and verification timestamp. Empty results explicitly state **“Availability not yet confirmed.”** Forecasts are not used to infer capacity.
- Added Sales Manager-controlled availability create/edit/deactivate operations with source reference, verifier, validity, actor audit entries, and append-only before/after capacity events. Updates use transactions, row locks, and optimistic `lock_version`; confirmed capacity cannot be reduced below reserved capacity.
- Added inquiry approvals for authorized Sales Managers; approval moves a submitted inquiry into review and does not create a forecast or shipment.
- Added the five requested permissions to the actual permission registry and friendly labels in Administrator → Roles & Permissions. Defaults for Sales and the new Sales Manager role are additive; existing assignments and custom role overrides are not synchronized or removed.
- Added permission-filtered Sales Dashboard, Inquiry Approvals, Shipment Calendar, and Shipment Availability sidebar entries while retaining existing Sales links.
- Login and `/dashboard` route to the appropriate authorized landing page. Authorization remains enforced by Laravel middleware/controller checks.
- Updated the calendar query so a weekly range can include forecasts by their requested shipping date, even if the first day of the forecast month is outside that selected week.
- Fixed a date-status edge case so a departure on the current date is not marked expired before the day ends, and aligned the Pallets unit value with backend validation.
- Added responsive IFC styling, loading/error/empty states, route detail dialog, capacity form dialog, and a 375px browser overflow check for the new dashboard.

## Verification

| Check | Result |
|---|---|
| `php artisan test --filter=SalesDashboardTest` | **4 passed, 39 assertions.** Covers role-based login and landing priority (including mixed roles), permission removal/403 without Executive access, owner-scoped summary/calendar data, forecast/operational separation, availability authorization, capacity limits, audit events, and stale-write conflict handling. |
| Targeted identity/role regression filter (`exact_initial_roles`, `direct_administration_routes`, `all_seeded_roles`, `roles_page_loads`, `role_permission_update_rejects`) | **5 passed, 193 assertions.** Includes fresh-schema role/seeder behavior and the new Sales Manager role. |
| `npm run test:frontend` | **9 passed.** Includes Sales link visibility by permission. |
| `npm run build` | **Passed** (Vite 7.3.7, 32 modules). Existing bundle-size warning: generated JS is about 521 kB minified. |
| `vendor/bin/pint --test` | **Passed.** |
| `npm run test:e2e` | **7 passed, 3 timed out.** The new Sales Dashboard browser flow passed, including month/week/list controls, unconfirmed availability, and 375px no-horizontal-overflow assertion. An existing Add Inquiry browser scenario and User Management overflow checks at 1440px and 375px timed out with the page/request still loading; no assertion established a Sales Dashboard regression. Overall browser suite is therefore not clean. |
| MySQL/concurrent database run | **Not run in this task.** Capacity update locking and version conflicts were tested on the configured Laravel test database, but no parallel request or MySQL contention test was executed. |
| `git diff --check` | Unavailable because the supplied project directory has no Git metadata. |

An initial broad identity-suite run exposed five failures: role creation in the new migration happened before the seeder and prevented its default permissions, and two tests still assumed nine roles/static permissions. Those issues were corrected. The affected regression subset then passed as shown above. The whole Laravel test suite was not run; results above are from the focused Sales feature suite and affected identity/role regressions.

## Files changed

- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/DashboardLandingController.php` (new)
- `app/Http/Controllers/SalesDashboardController.php` (new)
- `database/migrations/2026_11_05_090000_create_phase_seventeen_sales_dashboard.php` (new)
- `database/seeders/AccessControlSeeder.php`
- `app/Models/SalesCapacityAvailability.php` (new)
- `app/Models/SalesCapacityEvent.php` (new)
- `routes/web.php`
- `resources/js/App.vue`
- `resources/js/app.js`
- `resources/js/sidebarNavigation.js`
- `resources/js/components/RolesPermissions.vue`
- `resources/js/components/SalesDashboard.vue` (new)
- `resources/js/sales-dashboard.css` (new)
- `tests/Feature/SalesDashboardTest.php` (new)
- `tests/Feature/IdentityAndAdministrationTest.php`
- `tests/Frontend/sidebarNavigation.test.js`
- `tests/Browser/ifc-workflow.spec.js`
- `PHASE_18_SALES_DASHBOARD_REPORT.md` (this report)

## Remaining limits

- Capacity rows are an authorized internal register. The application does not connect to carriers or independently authenticate carrier confirmation; managers must enter a source/approval reference and record verification.
- Capacity update locking was verified through transaction and stale-version tests, not a real concurrent MySQL request race.
- The full Laravel suite and all browser E2E tests did not pass as a complete run; broader regression coverage remains outstanding.
- No human Sales/department UAT, production database, external carrier data, or production environment was accessed.
