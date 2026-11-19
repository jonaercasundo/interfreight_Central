# Phase 5 Shipment Data Model

## Master

`shipments` retains its Phase 1 fields and adds client, inquiry, forecast, quotation and accepted-baseline links; a unique master job number; source/originating department; shipment/service/container class; planned departure; master status; optimistic lock version; cancellation and archive fields. Legacy rows receive job numbers and retain their original references.

## Operational records

- `shipment_department_assignments`: one current assignment per department and master shipment.
- `shipment_timeline_events`: append-only activity chronology with actor, source and optional idempotency key.
- `shipment_status_history`: master and scope status changes.
- `shipment_milestones`: planned/actual department milestones.
- `shipment_customs_processes`: customs entry, permits, inspection and release state.
- `shipment_transport_trips`: one or more independently scheduled transport legs.
- `shipment_forwarding_bookings`: carrier, vessel/flight, route and schedule records.
- `shipment_containers`: FCL container and return tracking.
- `shipment_deliveries`: delivery confirmation and POD review state.
- `shipment_documents`: private files, category, version, expiry and verification metadata.
- `shipment_exceptions`: operational issues and resolution history.

Money amounts from estimates and Phase 4 allocations are summarized by currency without conversion. `freight_cost` remains a legacy field and is not treated as an actual expense.
