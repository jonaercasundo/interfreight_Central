# Phase 6 RBAC matrix

| Role | Access |
|---|---|
| Operations / Transport / Forwarding | Create, update, submit and upload documents for assigned department shipments; request and liquidate own advances; view scoped expense/variance records |
| Accounting | View ledger, verify expenses and receipts, review advances, record disbursements, verify returns/liquidations, view utilization and exceptions |
| Finance | View oversight, approve advances and disbursements, record client approval decisions, approve exception actions, view utilization and variance |
| Administrator | No implicit access is granted by the Phase 6 migration; explicit permission grants are required |
| Sales / Billing / Executive | No Phase 6 expense write permissions are assigned by this migration |

Every API route has Laravel `auth` plus permission middleware; controller checks enforce separation of duties and shipment/requester scope. UI visibility is only a convenience and does not replace server checks.
