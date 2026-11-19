# Phase 10: Completion milestones

The four independently verified dimensions are Operations, Billing, Collections, and Expense Reconciliation. Each stores status, verifier, verification time, notes, evidence JSON, and optimistic `lock_version`. API completion requires the corresponding domain permission and zero unresolved source blockers.

Operations reads Phase 5 milestones, trips, delivery/POD, customs, forwarding bookings, containers, documents, and open exceptions. Billing reads invoice workflow states, accepted commercial baseline linkage, and additional-charge decisions. Collections reads issued invoices, reconciled allocations, pending receipts, and receivable balances. Expense reconciliation reads verified actual expenses, currency consistency, and unresolved cash advances.

Missing optional scopes do not create phantom requirements. This first implementation only treats a customs record as required when present; shipment-specific policy for required permits/documents, partial deliveries, and return obligations remains to be configured.
