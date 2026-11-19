# IFC Phase 2 — Test Report

Run from `C:\Users\RHM\Desktop\interfreight-central` after the Sales inquiry and forecast implementation.

## Results

- `php artisan test`: **52 passed, 2 skipped**, 497 assertions. The skipped cases require optional live FastAPI end-to-end configuration (`AI_E2E_URL` and `AI_E2E_TOKEN`).
- Focused workflow run `php artisan test --filter=SalesForecastWorkflowTest`: **5 passed**, covering client and inquiry capture, inquiry state transitions, two monthly forecasts for one inquiry, revision history, validation failures, Sales ownership boundaries, Accounting read-only access, and page permissions.
- `npm run test:frontend`: **4 passed** (sidebar role and permission navigation behavior).
- `npm run build`: **passed** (Vite production build).
- `vendor/bin/pint --test` for changed PHP files: **passed**.
- Whitespace and conflict-marker scan across changed source/docs: **no matches**.
- `php artisan route:list --path=sales` and `--path=accounting/forecasts`: **passed**; Sales, Accounting, and API routes registered.

## Git diff check

`git diff --check` could not run because the supplied project directory has no `.git` repository metadata. A targeted scan for trailing whitespace and merge conflict markers was run over the changed files and returned no matches.

## Data safety

The test suite uses Laravel `RefreshDatabase` test isolation. The additive migrations were also applied with `php artisan migrate --force` only after confirming the configured environment is `local` and the connection is the project SQLite file (`database/database.sqlite`). No database reset was run, production data was not edited, and nothing was deployed. Spatie's permission cache was flushed after the local migration.
