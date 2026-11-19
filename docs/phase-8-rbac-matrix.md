# Phase 8 RBAC Matrix

| Role | Billing access |
|---|---|
| Billing | Dashboard, readiness, invoice view/create/update/submit, controlled issue permission (still blocked by legal config), documents, history, exceptions |
| Accounting | Dashboard, invoice view/review/approve/download/history, exception visibility |
| Finance | Dashboard, invoice view/review/approve/download/history, exception visibility |
| Sales | Invoice status visibility permission |
| Operations / Transport / Forwarding | Billing readiness visibility |
| Executive | Dashboard/report visibility |
| Administrator | No new billing grants in the additive role-grant migration; administrator role alone cannot review or issue |

All API routes are under the existing authenticated, active-account, password-session middleware group and use granular `can` middleware. Review logic additionally checks Accounting/Finance role membership and segregation of duties. Sales/operations scoping is not added to invoice details; those roles receive no invoice-detail API permission by this migration.
