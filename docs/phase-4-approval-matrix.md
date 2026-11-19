# Phase 4: Approval Matrix

| Role | Prepare / edit draft | Submit | Review | Approve | Allocate | Oversight |
| --- | --- | --- | --- | --- | --- | --- |
| Accounting | Yes | Yes | No | No | Yes, after approval | Operational funding scope; can close an approved budget cycle |
| Finance | No | No | Yes | Yes when configured | No | Plans, history, variance |
| Executive | No | No | No by default | No by default | No | Read-only summary |
| Sales | No | No | No | No | No | No company funding data |
| Administrator | Not granted by Phase 4 | Not granted | Not granted | Not granted | Not granted | No financial role scope |

`IFC_BUDGET_APPROVAL_ROLES` defaults to `Finance`. The controller requires both `budgets.approve` and membership in the configured approver role set. `IFC_BUDGET_PREVENT_SELF_APPROVAL` defaults to true and blocks the submitter from reviewing or approving their own submission. The migration does not assign monetary approval thresholds. The default review path is Accounting submission followed by Finance decision; an Executive stage requires explicit permission grants and deployment configuration.

Budget approval is a single configured final approval in the current interface. If the company requires multiple sequential approval stages, the approval matrix and staged transition policy must be extended before use.
