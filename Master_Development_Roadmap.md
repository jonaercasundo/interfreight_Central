InterFreight Central (IFC)
Master Development Roadmap — Laravel 13 + Vue 3 + Python FastAPI

Based on your workflow diagram, I recommend building InterFreight Central (IFC) in 12 development phases, followed by production deployment.

The application must follow one important principle:

Forecast First → Approve Before Spending → Track Actual Expenses → Protect Profitability.

The system must also solve your three major operational problems: accounting cannot forecast upcoming cash requirements, departments report different completed-shipment totals, and additional expenses can reduce company profit when clients reject the charges.
1. Master development phases
Development roadmap
12 phases

Suggested build order — phases have dependencies, not equal development effort
1. Foundation and Security

   Architecture, database, authentication, RBAC, audit logs
2. Client and Shipment Forecast

   Client records, inquiry, shipment demand forecasting
3. Cost Forecast and Quotation

   Internal cost estimation, client quotations, locked approvals
4. Accounting Budget Allocation

   Monthly budget, cash requirements, cash advances
5. Shipment Operations

   Job creation, operations, transport, forwarding, documents
6. Expense Monitoring

   Actual expenses, receipts, liquidation, budget comparisons
7. Additional Charge Approval

   Client approval, rejection, company-absorbed losses
8. Billing and Invoicing

   Validated charges, invoices, billing controls
9. Collection and Accounting

   Payments, receivables, reconciliation
10. Shipment Closing and Profitability

    Unified lifecycle, department KPIs, final profitability
11. Dashboards and AI

    Executive analytics, forecasting, alerts, Python AI services
12. System Testing and UAT

    Full integration, security, performance, user acceptance

2. Detailed phase requirements

Phase 1 — System Foundation and Security

Create the IFC application shell, database architecture, department roles, permissions, audit logs, and secure Laravel–Python communication.

Roles: Administrator, Executive, Sales, Operations, Transport, Forwarding, Accounting, Billing, and Finance.

Deliverable: A working multiuser application with secure department navigation.




Phase 2 — Client Inquiry and Shipment Forecast

Record client inquiries, service requirements, LCL/FCL, cargo details, planned schedules, and expected shipment volume by month.

Accounting must see forecasted shipment demand before quotation or billing.

Deliverable: Shipment forecast calendar and monthly demand dashboard.




Phase 3 — Cost Forecast and Client Quotation

Calculate expected shipping-line fees, transport, forwarding, customs, cash advances, miscellaneous costs, and contingency.

Generate quotations using estimated costs, selling rates, and target margins. Approved quotations become locked, versioned commercial baselines.

Deliverable: Cost estimation, quotation approval, revision history, and margin preview.




Phase 4 — Accounting Budget Allocation

Consolidate shipment forecasts and approved quotations into monthly funding requirements.

Allocate funds for shipping-line payments, cash advances, transport, forwarding, and other operating costs. Track approved budget versus committed and actual expenses.

Deliverable: Monthly budget plan, allocation approvals, and cash requirement forecast.




Phase 5 — Shipment Execution

Create a unique shipment/job number and coordinate Operations, Transport, and Forwarding.

Include bookings, documentation, vessel/airline details, customs processing, truck assignments, milestones, delivery confirmation, and POD.

Deliverable: Unified shipment workspace and departmental operational views.




Phase 6 — Actual Expense Monitoring

Record actual expenses with receipt attachments, categories, department ownership, cash advances, and liquidation.

Compare each expense against the approved forecast and budget. Flag overruns and missing supporting documents.

Deliverable: Actual expense ledger and budget utilization monitoring.




Phase 7 — Additional Charge Approval

When unexpected costs occur, record the reason, amount, evidence, and whether the cost is billable.

Request client approval before adding a new charge to billing. If rejected, record it as a company-absorbed expense and show its effect on profit.

Deliverable: Client approval workflow, evidence tracking, and loss classification.




Phase 8 — Billing and Invoicing

Generate invoices from the approved quotation and explicitly approved additional charges.

Exclude rejected or pending additional charges. Prevent duplicate billing and retain invoice revisions, payment terms, and supporting documents.

Deliverable: Controlled invoice generation and billing register.




Phase 9 — Collection and Accounting

Record customer payments, partial collections, outstanding balances, due dates, aging, and payment reconciliation.

Deliverable: Accounts receivable, collection monitoring, and payment history.

Phase 9 implementation baseline: issued-invoice receivables, pending payment capture, independent Finance verification, multi-invoice allocations, manual statement matching, unapplied credits, customer ledger, as-of aging, and internal follow-ups are implemented in `docs/phase-9-collection-accounting.md`.

Remaining before full accounting close: invoice credit notes/adjustments, payment reversals, write-offs/disputes, bank file imports or connections, complete filtered/export reports, and collection performance charts. Phase 8 statutory invoice issuance remains gated pending verified company invoice configuration.




Phase 10 — Shipment Closing and Profitability

Introduce operational completion, billing completion, collection completion, and financial closing as distinct milestones.

Calculate shipment profitability after expenses, approved revenue, and absorbed losses are reconciled.

Deliverable: Final shipment profitability and unified completion reporting.

Phase 10 implementation status: closing register, independent completion verification, source-backed financial summaries, approval/audit schema, role-scoped access, and immutable snapshot storage are present. Final financial close remains blocked until a verified revenue-recognition source and policy are configured; do not substitute invoice totals or collected cash for recognized revenue. See `docs/phase-10-closing-architecture.md` and the Phase 10 documents for implemented scope and remaining controls.




Phase 11 — Executive Dashboard and Python AI

Status: In progress. An Executive-only Command Center is available at `/executive`, with period-filtered source-backed master shipment, forecast, completion, budget, verified expense, invoicing, reconciled collection, receivable, department-assignment, and exception metrics. Currencies remain separate and recognized revenue/profit remain explicitly unavailable until approved accounting sources exist.

A deterministic FastAPI shipment-demand baseline is connected through bounded signed Laravel-to-Python requests. Forecast runs preserve aggregate inputs, model version, cutoff, evaluation, output, actor and timestamp. Low-history results state that data is insufficient.

Cost anomaly review, document extraction, grounded natural-language financial reporting, recommendation governance, model monitoring, executive report exports, and deeper department/service/client analytics remain to be implemented. See `docs/phase-11-*` for current contracts, limitations and authorization scope.




Phase 12 — Quality Assurance and UAT

Perform integration tests, permission tests, financial calculation verification, approval security tests, concurrency tests, backups, restore drills, and user acceptance testing.

Deliverable: Release candidate approved for production deployment.



3. Critical business rules
These rules should be implemented at the database and service layers, not only through frontend restrictions.
Rule	Required behavior
Forecast before quotation	Internal cost estimate must exist before quotation approval
Budget visibility	Accounting sees forecasted shipment volumes and cash requirements
Approved quotation	Locked baseline; changes require a controlled revision
Unexpected expense	Actual cost is recorded even if client approval is pending
Rejected charge	Excluded from invoice, retained as company-absorbed cost
Shipment completion	Department-specific metrics derived from shared shipment records
Financial closing	Requires expense reconciliation and collection settlement, or an authorized exception
Auditability	Record who changed, approved, rejected, or closed each transaction

4. Unified shipment status design
Your diagram correctly highlights that Transport may report 50 completed shipments, Operations 45, and Accounting 30. These numbers can all be valid because each department measures a different milestone.

The solution is one shipment record with multiple independent completion statuses, rather than separate disconnected shipment totals.
Single IFC Shipment Record

Unique job number shared by every department


Operations

Processing completed


Transport

Delivery / POD completed


Billing

Invoice issued


Accounting

Collection completed




Financially Closed

Reconciled expenses, settlement, final profit and archived documents


This prevents inconsistent reporting while preserving legitimate departmental differences.
5. Recommended implementation schedule
The following is an illustrative schedule for one experienced developer using AI coding assistance. Actual duration depends on finalized requirements, integrations, design complexity, and testing.
Estimated development effort by phase
Working weeks per phase, assuming mostly sequential implementation.


0 weeks2 weeks4 weeks6 weeksP1P2P3P4P5P6P7P8P9P10P11P12














Illustrative total
30 weeks

Production deployment

After UAT approval


6. How Codex should execute the roadmap
I recommend that Codex implement one phase at a time, with each phase containing database migrations, Laravel business services, Vue pages, API endpoints, permission enforcement, and automated tests.

For every phase, Codex should follow this cycle:
1

Inspect

2

Design

3

Build

4

Test

5

Review



Do not let Codex mark a phase complete simply because pages are visible. Completion requires functional CRUD, permissions, correct calculations, validations, passing tests, and integration with the previous phases.
7. First Codex AI agent command
Your existing IFC project already has Laravel, Vue/Vite, and Python FastAPI foundations. Therefore, Codex should inspect and reuse what exists instead of creating a new project.
You are the Lead Software Architect and Principal Engineer for InterFreight Central (IFC).

PROJECT:
C:\Users\RHM\Desktop\interfreight-central

COMPANY:
INTERFREIGHT GROUP

SYSTEM:
InterFreight Central (IFC) — Centralized Shipment Management System

OBJECTIVE:
Begin Phase 1 of the IFC master development roadmap based on the approved business workflow diagram.

CORE PRINCIPLE:
Forecast First → Approve Before Spend → Track Actual → Protect Profitability.

PHASE 1 SCOPE:
1. Inspect all existing Laravel, Vue, Python FastAPI, database, and Docker source files.
2. Preserve existing working functionality.
3. Document the current architecture and identify missing foundational components.
4. Establish a maintainable modular Laravel architecture for the 17 planned system modules.
5. Implement authentication and role-based access control for Administrator, Executive, Sales, Operations, Transport, Forwarding, Accounting, Billing, and Finance.
6. Implement database-backed permissions and audit logging.
7. Prepare the MySQL 8 schema and migrations using correct foreign keys, indexes, and decimal financial types.
8. Configure Redis caching and background queues.
9. Complete secure Laravel-to-Python FastAPI communication with service authentication, validation, timeouts, and safe logging.
10. Build the responsive IFC dashboard shell, department navigation, breadcrumbs, and shared UI components.
11. Implement automated Laravel and Python tests for the foundation.
12. Document installation, configuration, and development commands.
ARCHITECTURAL REQUIREMENTS:
- Laravel is the source of truth for business transactions.
- Python is responsible for AI, analytics, and automation services.
- All departments must eventually share a canonical shipment record.
- Shipment status must support separate operational, transport, billing, collection, and financial milestones.
- Approved quotations must support immutable versions.
- Expenses must remain traceable regardless of client billing approval.
- Financial records must be auditable.
- Do not implement incomplete or invented financial business rules.
- Do not create fake production data.
EXECUTION:

Complete routine implementation tasks without repeated approval prompts.

Do not deploy to production, destroy existing data, overwrite unrelated projects, or execute destructive migrations without explicit authorization.

Run all available tests, fix errors, and report verified results.

FINAL REPORT:
- Existing components reused
- Files created or modified
- Database migrations
- Security implementation
- Laravel–Python integration results
- Automated test results
- Remaining blockers
- Phase 1 completion status
- Readiness for Phase 2
Begin Phase 1 implementation now.
