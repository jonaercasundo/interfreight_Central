# Phase 9 RBAC Matrix

| Permission | Accounting | Finance | Billing | Executive |
| --- | --- | --- | --- | --- |
| `collections.view` | Yes | Yes | Yes | Yes |
| `collections.record` | Yes | No | No | No |
| `collections.reconcile` | No | Yes | No | No |
| `collections.reverse` | No | Yes | No | No |
| `collections.history.view` | Yes | Yes | Yes | No |
| `collections.followups.view` | Yes | Yes | No | Yes |
| `collections.followups.manage` | Yes | No | No | No |

The controller checks the permission and expected department role for receipt entry and reconciliation. A Finance actor cannot reconcile a receipt they recorded, including when the actor holds both roles.

Statement-credit entry/matching and verified receipt reversals are restricted to Finance with their explicit permissions. Follow-up creation/status changes are controlled by `collections.followups.manage`; viewing is separately authorized.
