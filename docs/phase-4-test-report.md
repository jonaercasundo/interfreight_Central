# Phase 4: Test Report

## Automated checks

- `php artisan test`: 65 passed, 2 optional external-service tests skipped, 708 assertions.
- `php artisan test --filter=BudgetWorkflowTest`: 5 passed, 55 assertions.
- `npm run test:frontend`: 5 passed.
- `npm run build`: passed.
- `vendor/bin/pint --test`: passed.
- `git diff --check`: unavailable because this workspace has no `.git` directory. A custom trailing-whitespace and conflict-marker scan was run against changed text files.
- Additive migrations `2026_10_25_090000`, `090001`, and `090002` applied to the configured local development database (`APP_ENV=local`). No reset or seed command was run.

## Feature coverage

- Private Accounting dashboard route; anonymous and Sales denial.
- Forecast demand derives funding from approved cost estimate lines and excludes quotation selling prices.
- Newer draft estimates remain a distinct provisional demand layer and do not change approved totals or allocation eligibility; tentative forecasts remain visible.
- Accounting draft creation, exact decimal total, submit and Finance approval.
- Submitter self-approval denial.
- Approved budget update lock and immutable parent line snapshot on revision.
- Budget line allocation cap and over-allocation rejection.
- Dashboard requirement shortfall and available authority update from real allocation records; budget close is recorded.
- Audit history and approval-step writes.
- Missing actual and verified cash values are returned as explicit unavailable states.

## Not covered / dependencies

There is no operational commitments, actual expenses, cash advances, payment timing or verified cash module to reconcile. Multi-stage Finance/Executive approval, notifications, department-level accounting scopes, and month-by-month charts await company policy or source integrations. SQLite test runs cannot establish production MySQL concurrent lock behavior; the transactional locked path must also be verified against the supported MySQL deployment environment before go-live.
