# Phase 10: RBAC matrix

| Role | Phase 10 access |
| --- | --- |
| Operations | View completion; verify operations |
| Transport | View completion and reconciliation |
| Forwarding | View completion and reconciliation |
| Billing | View completion; verify billing |
| Accounting | View/verify collections; manage reconciliation; review and view close history |
| Finance | View profitability and cost details; prepare/submit/review/close/reopen; view/export closing reports |
| Executive | View high-level profitability; approve closing; view snapshots/history |
| Sales | No closing access by default |
| Administrator | No Phase 10 business authority by administrator role alone |

Routes enforce permission middleware, and controller actions check permission again where workflow actions require it. Future scope rules must additionally filter by departmental assignment/client ownership before widening view permissions.
