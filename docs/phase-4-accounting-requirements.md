# Phase 4: Accounting Requirements

## Scope delivered

The IFC Phase 4 accounting workspace uses the existing authenticated application shell. Accounting users can inspect forecast demand and approved cost-estimate funding needs, create monthly budget drafts, submit them to Finance, revise returned or approved plans, and reserve approved budget line authority against a current eligible funding requirement. Finance can review, request changes, reject, and approve submitted plans. Executive access is read-only unless an authorized permission and approval policy are configured.

Pages are available under `/accounting`: dashboard, overview, shipment demand, funding requirements, monthly budget plans, budget allocations, cash forecast, commitments and actuals, variance reports, and budget history. Every route and API uses Laravel authentication and granular `can` middleware, followed by department checks in the controller.

## Data source constraints

Active Phase 2 forecasts at any confidence (`tentative`, `probable`, or `confirmed`) provide shipment counts and month. The latest approved Phase 3 cost-estimate version supplies approved demand lines where `funding_required` is true. A newer unapproved estimate version is shown separately as provisional assumptions; it never contributes to approved totals, shortfall, or allocation eligibility. Forecasts without a cost estimate remain visible as provisional with no amount. Estimated cost values are multiplied by forecast shipment counts and shown in estimate currency. Selling prices from quotations are never included in funding demand.

The existing Phase 2–3 source schema does not contain expected payment dates, a commitment register, an actual-expense ledger, cash advances, verified cash balances, or a payment ledger. Those sections state `Not recorded` or `Not integrated`; no financial records are fabricated and no payment action is provided.

## Roles

- Accounting: demand and plan preparation, submission, allocations, variance and history access.
- Finance: review and configured approval authority, oversight, variance and history access.
- Executive: read-only oversight by default. Add explicit permissions and configure `IFC_BUDGET_APPROVAL_ROLES` before allowing executive approval.
- Sales: no company funding or budget access.
- Administrator: no automatic approval authority; dashboard handlers also require a financial department role.

No official monetary thresholds are configured because none were supplied.

The stored `fiscal_year` currently follows the calendar year because the company's fiscal-year start rule was not provided. Change this scope rule through configuration when Accounting confirms the fiscal calendar.
