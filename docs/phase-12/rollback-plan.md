# Rollback Plan (Draft)

This is a planning artifact, not an executable production runbook. No deployment or rollback was performed.

1. Identify the exact approved release artifact and prior known-good artifact by immutable digest.
2. Confirm backup and restore point are verified; record owner and rollback decision authority.
3. Prefer application rollback only when schema changes are backward-compatible. Never issue destructive down migrations against production without a separately reviewed recovery plan.
4. If data correction is needed, stop writes and use a separately approved, audited reconciliation procedure; do not overwrite business records.
5. Restore only under explicit authorization into an approved target. Validate database and document links before reopening traffic.
6. Verify login, role authorization, shipment, billing, collection and health smoke checks; monitor errors and queue backlog.
7. Record timeline, data cutoff, decisions and post-incident actions.

Infrastructure-specific commands, RTO/RPO, owners and approval chain remain unknown and must be completed by operations before deployment.
