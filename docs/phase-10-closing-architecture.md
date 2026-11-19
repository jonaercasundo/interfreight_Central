# Phase 10: Shipment closing architecture

Phase 10 adds an independently versioned closing workflow on top of the Phase 5 master shipment identity and the Phase 3–9 source ledgers. `shipment_completion_milestones` records separate operations, billing, collections, and expense-reconciliation verification. `financial_closing_requests` and `financial_closing_reviews` record controlled approval history. `financial_closing_snapshots` stores append-only JSON snapshots with a SHA-256 digest.

The register reads shipments once per page and loads milestones and latest closing requests in batches. A selected shipment detail is calculated from its source records. Closing state never overwrites `shipments.master_status`; departmental completion and financial closing are independent.

Finance/Accounting/Executive permissions are granted explicitly. Administrator receives no Phase 10 business permissions. Snapshots are only inserted during a valid approved close; there are no update or delete endpoints.

**Current limitation:** Phase 1–9 has no verified revenue-recognition event or policy source. The service therefore returns recognized revenue, profit, and margin as unknown and blocks close. Invoice value and reconciled customer cash are displayed separately. A configurable recognition policy and verified event workflow are required before production closing can be enabled.
