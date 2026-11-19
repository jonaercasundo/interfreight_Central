# IFC AI Usage & Cost Monitoring — Implementation Report

**Date:** 2026-10-24
**Scope:** Administrator dashboard summary, usage reporting, auditable PHP cost estimates, and budget controls.
**Database:** Local SQLite only; the populated file was backed up before applying the additive migration. No production or MySQL database was changed.

## Delivered

- Added an Administrator-only **AI Usage & Costs** workspace at `/admin/ai-usage`, linked from Administration navigation and the administrator dashboard.
- Added KPI cards for monthly requests, successes/failures, input/output tokens, estimated PHP spend, and cost coverage. The page includes request activity, department and module summaries, verified pricing/FX histories, filters, pagination, and CSV export.
- AI provider responses now report input, cached-input, and output token counts for assistant calls and explicit connection tests. Laravel logs request ID, correlation ID, category, module, department, status, latency, model and token facts. Provider credentials are not sent to the browser or included in usage records.
- Added administrator-managed, effective-dated model price records with source URL and verification date. Added auditable, effective-dated USD/PHP rate overrides with source and reason. Records are append-only so prior rate versions remain available.
- Cost estimates are calculated only when the event has provider token counts and matching effective model pricing and USD/PHP rate records. Missing facts remain `NULL` and display as **Unavailable**; no default prices, exchange rates, historical cost backfill, or fabricated figures were added.
- Added monthly PHP budgets with configurable warning and critical percentages. Threshold alerts are recorded once per month/threshold and shown in the usage workspace. They do not send email or external notifications.
- Added CSV export with spreadsheet formula-injection escaping for text cells.
- Registered `admin.ai-usage.view`, `admin.ai-usage.export`, `admin.ai-pricing.manage`, `admin.ai-fx.manage`, and `admin.ai-budget.manage`. Migration and seeder grant them to Administrator only by default; existing custom assignments are retained.
- Added an AI usage summary panel to the Administrator Dashboard. Non-administrator dashboard payloads do not receive these metrics.

## Database change and preservation

New additive migration: [`2026_11_08_090000_extend_ai_usage_cost_monitoring.php`](database/migrations/2026_11_08_090000_extend_ai_usage_cost_monitoring.php).

It adds request/module/category and token metadata to `ai_usage_events`, plus `ai_model_prices`, `ai_exchange_rates`, `ai_monthly_budgets`, and idempotent `ai_budget_alerts` tables. Existing usage rows receive no invented costs. No table was dropped, and the migration does not rewrite existing AI configuration or usage records.

Before applying the sole pending migration, the populated local SQLite file was copied to:

`C:\Users\RHM\Desktop\IFC-db-safety-backups\interfreight-central-pre-ai-usage-cost-20261024-223211.sqlite`

The copy and source SHA-256 hashes matched. After migration, both source and backup reported 4 users, 22 AI usage rows, `integrity_check=ok`, and zero foreign-key violations. The source has the new columns; the backup correctly remains pre-migration. Migration batch 27 is recorded as `Ran` locally. No MySQL migration was performed.

## Pricing and provider boundaries

Rates are estimates from stored token counts and administrator-entered source records; they are not OpenAI invoices or settled provider charges. The configured model has no seeded price, and the published OpenAI pricing reference did not list that model when checked. The application therefore leaves its PHP cost unavailable until an administrator records a verified price and an effective USD/PHP conversion rate. See [OpenAI API pricing](https://developers.openai.com/api/docs/pricing).

The separate organization Costs API requires an organization-admin credential. It was not connected or enabled as part of this implementation; provider invoice reconciliation remains out of scope until that credential and authorization are separately configured. See [OpenAI organization Costs API](https://developers.openai.com/api/reference/resources/admin/subresources/organization/subresources/usage/methods/costs).

Exchange rates currently use the administrator override workflow with source attribution, verification timestamp and audit reason. No automatic BSP feed or scheduled rate importer was selected or configured. Budget alerts are surfaced when an authorized dashboard/usage summary is loaded; no email delivery was added.

## Main files

- Backend: `app/Http/Controllers/AdminAiUsageController.php`, `app/Http/Controllers/Api/DashboardController.php`, `app/Http/Controllers/AiConfigurationController.php`
- Models: `app/Models/AiUsageEvent.php`, `AiModelPrice.php`, `AiExchangeRate.php`, `AiMonthlyBudget.php`
- Services: `app/Services/AiCredentialService.php`, `AiAssistantClient.php`, `AiServiceClient.php`
- UI/navigation: `resources/js/components/AiUsage.vue`, `resources/js/components/RolesPermissions.vue`, `resources/js/App.vue`, `resources/js/sidebarNavigation.js`, `resources/js/style.css`
- Python: `python-ai/app/main.py`
- Permissions/routes: `database/seeders/AccessControlSeeder.php`, `routes/api.php`, `routes/web.php`
- Tests: `tests/Feature/AiUsageMonitoringTest.php`, `tests/Frontend/sidebarNavigation.test.js`, `python-ai/tests/test_api.py`

## Verification results

| Check | Result |
|---|---|
| Full Laravel suite (`php artisan test`, in-memory SQLite) | **136 passed, 5 skipped, 0 failed; 1,438 assertions; 557.37 seconds.** Skips require live AI endpoint credentials, ClamAV, or the host PHP Redis extension. |
| Focused AI usage feature tests | **4 passed; 31 assertions.** Covers Administrator authorization, write denial for Sales, verified pricing/rate arithmetic, missing-price behavior, filtering, CSV, budgets, and audit records. |
| Python (`pytest python-ai/tests -q`) | **33 passed.** One upstream Starlette/httpx TestClient deprecation warning. |
| Frontend (`npm run test:frontend`) | **11 passed.** |
| Frontend production build (`npm run build`) | **Passed**; Vite built 36 modules. It emitted the existing advisory that the main JS bundle exceeds 500 kB. |
| Laravel Pint (`vendor/bin/pint --test`) | **Passed.** |
| Route registration | Verified 7 AI usage routes for page, summary, list, export, pricing, FX and budget. |
| `git diff --check` | **Unavailable:** this project directory has no Git metadata. The changed source files were checked separately for trailing whitespace. |

## Remaining work

- Have Finance approve whether model usage estimates are acceptable for internal charge monitoring and define who verifies model prices and USD/PHP rates.
- Decide on an authoritative FX source and scheduled update policy if manual overrides are insufficient.
- Configure an OpenAI organization-admin Costs API credential separately if invoice-level provider reconciliation is required.
- Add real in-app/email delivery only if a notification owner and recipient policy are approved.
- Run a MySQL 8.4 migration and query verification in a disposable schema before promoting this migration to any MySQL environment.

**Production readiness:** This feature is locally implemented and SQLite-tested. It does not change the project’s production-readiness decision; model cost remains unavailable until verified pricing and FX records exist, and production/MySQL deployment was not performed.
