# Phase 10: Department reconciliation

Enterprise closing records are keyed to one unique `shipments.id` / Job Number. Shipment register pagination counts master rows once. Departmental evidence is read from assignments, trips, deliveries, bookings, containers, milestones, and source documents; these child records do not increase the master shipment count.

The Phase 10 detail currently surfaces operational blockers and completion dimensions. A full cross-module reconciliation report with counts by assignment, trip, delivery, container, billing, collection, and close status remains future work.
