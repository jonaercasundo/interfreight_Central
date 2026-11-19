# Phase 4: Budget Data Model

The additive migration `2026_10_25_090000_create_phase_four_budget_tables` creates:

- `monthly_budget_plans`: month, fiscal year, department, currency, owner, version, parent version, workflow status, request/approval totals, submitter and approver timestamps.
- `budget_lines`: one row per plan/category, requested amount, immutable approved amount after approval, explanatory adjustment reason.
- `budget_approval_steps`: append-only review actions, actor, status transition, remarks and timestamp.
- `budget_allocations`: reservation reference, approved plan/line, stable requirement source key, amount, currency, purpose, actor and date.
- `budget_audit_history`: append-only before/after snapshots and reason for plan changes.

Foreign keys preserve referenced users and parent plans. Unique keys scope plan versions and budget-line categories. Approval and allocation actions use a database transaction and row locks. Allocation target identity is a stable estimate reference, version and cost-line ID; a current approved source is revalidated before reservation. The source requirement is computed from Phase 2–3 records rather than copied to a second system of record.

Phase 4 permissions are registered in the migration. Grants are additive and restricted to Accounting, Finance and Executive roles according to the access matrix. Administrator is not granted the financial approval role by this migration.
