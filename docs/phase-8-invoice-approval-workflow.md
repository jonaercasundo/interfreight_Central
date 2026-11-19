# Phase 8 Invoice Approval Workflow

Billing preparers create and submit drafts. Accounting or Finance users with `billing.invoices.review` may approve, reject, or request a revision. The server additionally requires Accounting/Finance role membership and prevents self-review. Comments are required and stored in approval and event history. Administrator status alone does not authorize review. Approval records are separate from issuance and collection. Configurable financial thresholds are not present and have not been invented.
