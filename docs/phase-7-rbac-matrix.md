# Phase 7: RBAC Matrix

| Role | Phase 7 access |
| --- | --- |
| Administrator | All registered Phase 7 permissions through the existing system administrator grant; no operational menu item |
| Executive | View ACRs, commercial changes, recoverability and profit impact |
| Sales | View, review, prepare client requests, record client response, see history and authorize commercial changes |
| Operations | View/create/update/submit own department requests and history |
| Transport | View/create/update/submit own department requests and history |
| Forwarding | View/create/update/submit own department requests and history |
| Accounting | View/review, verify decision evidence, classify recoverability, view profit impact/history |
| Finance | View/review, verify decision evidence, classify recoverability, commercial view, profit impact/history |
| Billing | Read-only approved commercial change authorization list |

Routes apply Laravel `auth`, active-account and password-session middleware. State-changing APIs also use named Spatie permissions, shipment assignment checks, requester separation-of-duties, transactions, row locks, validation, private evidence download scoping, and event/audit recording. Role grants are in `AccessControlSeeder`; running the seeder preserves existing grants via additive `givePermissionTo` calls.
