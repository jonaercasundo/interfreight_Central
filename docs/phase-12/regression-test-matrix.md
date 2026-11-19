# Phase 1–11 Regression Test Matrix

Status describes repository test evidence, not full business acceptance. The Laravel feature suite is summarized in [the final report](phase-12-final-report.md).

| Phase | Existing automated evidence | Remaining validation |
|---|---|---|
| 1 Foundation/security | Identity/admin tests cover login/logout session handling, password flows, RBAC, audit, user administration, session-sensitive access. | Browser session/CSRF matrix across all clients and production session/cache settings. |
| 2 Client inquiries | Sales forecast workflow tests exist. | Full role CRUD, forecast revisions, month aggregation/accounting visibility in browser and representative data. |
| 3 Cost/quotation | Commercial workflow includes decimal calculator and estimate-to-acceptance baseline path. | Independent fixture coverage for all edge cases, immutability under direct mutations, UAT. |
| 4 Budget | Budget workflow tests cover approval, caps, permissions, snapshots, shortfall and forecast basis. | MySQL concurrent allocation and production-sized reports. |
| 5 Shipment execution | Shipment workspace/API tests exist. | Scenario E direct transport, department isolation and concurrent master ID generation on target DB. |
| 6 Actual expenses | Expense workspace tests cover authorization, assigned shipment, idempotency, receipt gate. | Full advance disbursement/liquidation and budget-to-expense reconciliation matrix. |
| 7 Additional charges | Workflow tests cover verified evidence, partial amount, rejection and billing eligibility. | Broader transaction concurrency and client-level operational UAT. |
| 8 Billing | Billing feature tests exist, including authorization and invoices. | Statutory issuance configuration, PDF security and concurrency under target DB. |
| 9 Collections | Billing/collections tests cover receipt, reconciliation, credit, reversal, ledger/outstanding and follow-up paths. | Bank statement import/reconciliation at production scale, full aging and multi-currency policy validation. |
| 10 Closing | Closing tests cover permissions, independent milestones and unknown revenue blocking closure. | Full blockers, immutable snapshot direct mutation, reopening and cross-ledger reconciliation. |
| 11 Executive/AI | Executive analytics, client construction, API signing/replay/validation and rolling-origin baseline tests. | Real history model evaluation; anomaly detection, extraction, grounded answers, recommendations, review controls and monitoring are not implemented per Phase 11 report. |

Do not upgrade a row to complete solely because its existing tests passed. See [integration](integration-test-report.md), [financial](financial-reconciliation-report.md), and [API](api-python-test-report.md) reports.
