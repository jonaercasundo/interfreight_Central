# Phase 5 Shipment RBAC Matrix

All endpoints require the authenticated, active web session and existing password-session middleware. UI visibility is an aid; each operation is protected server-side by permissions and shipment visibility checks.

| Role | Shipment scope | Typical Phase 5 access |
|---|---|---|
| Administrator | Company-wide | View register, timeline, reconciliation; separate permissions govern mutation |
| Executive | Company-wide | View register, timeline, reconciliation |
| Sales | Owned client, inquiry and quote records | Create from own accepted quotation, view allowed jobs and timeline |
| Operations | Unassigned queue and Operations-assigned jobs | Direct creation, update/assign/cancel as granted, customs, document verification, milestones, exceptions |
| Transport | Unassigned queue and Transport-assigned jobs | Direct creation, trip/dispatch, delivery, POD and exception actions |
| Forwarding | Unassigned queue and Forwarding-assigned jobs | Direct creation, booking, container, milestone and exception actions |
| Accounting | Company-wide read-only | Shipment register, timeline, funding readiness |
| Billing | Existing permissions apply | No Phase 5 billing/posting workflow is introduced |
| Finance | Company-wide read-only | Shipment register, timeline, funding readiness |

Permission additions are additive and use the existing role records. This implementation does not change role membership or grant shipment mutation rights to Administrator/Executive by implication.
