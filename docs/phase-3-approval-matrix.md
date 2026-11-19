# Phase 3 Approval Matrix

| Action | Sales | Accounting | Finance | Executive | Administrator |
|---|---|---|---|---|---|
| Create/edit own estimate | Yes | No | No | No | Yes |
| Submit estimate | Yes | No | No | No | Yes |
| Review estimate | No | Yes | No | No | Yes |
| View cost demand/cash | Own estimates | Yes | Permission scoped | No by default | Yes |
| Prepare/submit quotation | Yes | No | No | No | Yes |
| Accounting quotation review | No | Yes | No | No | Yes |
| Finance quotation review | No | No | Yes | No | Yes |
| Final quotation approval | No | Configurable permission | Yes | Yes | Yes |
| Issue own approved quote | Yes | No | No | No | Yes |
| Record client response | Yes | No | No | No | Yes |
| View margin analysis | Own commercial scope | No | Yes | Yes | Yes |

Every action also requires its Laravel permission. UI visibility is not authorization. `margins.policy.manage` is created for Administrator and Finance; thresholds and required review stages are centrally configured. Adjust role grants through the existing roles and permissions administration process, not by changing sidebar visibility.
