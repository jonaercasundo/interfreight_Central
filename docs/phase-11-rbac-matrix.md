# Phase 11 RBAC Matrix

| Role | Executive dashboard | Shipment forecast generation | Reason |
|---|---:|---:|---|
| Executive | Yes | Yes | Existing `executive.overview.view`; new `ai.forecasts.view` and `ai.forecasts.generate` |
| Administrator | No | No | System administration must not imply confidential business-data access |
| Finance | No | No | Existing financial workflow permissions remain unchanged; no Phase 11 analytics grant is inferred |
| Accounting | No | No | Existing accounting workflow permissions remain unchanged |
| Sales | No | No | Existing Sales owner-scoped forecast workflow remains unchanged |
| Operations, Transport, Forwarding | No | No | Existing department shipment access remains unchanged |
| Billing | No | No | Existing billing/collection access remains unchanged |

The Executive routes check both Laravel permission middleware and the Executive role in the controller. Menu visibility is permission- and role-aware. Prediction responses contain monthly aggregate counts only. Finance and department-specific executive datasets require separate granular grants and row-scope decisions before implementation.
