# Phase 10: Closing snapshots

Closing snapshots capture schema version, timestamp, master job/client identity, independent milestone state, financial facts, request version, approval identities/actions, source cutoff, and recognition-policy identifier. The canonical JSON string is stored with SHA-256 and a unique shipment/version constraint. There is no snapshot edit/delete endpoint; historical snapshots are read through closing history.

This initial snapshot contains source summaries and review records, not a complete immutable copy of every source document. Document retention, canonical serialization policy, and source-row locking across all Phase 1–9 writers are required before using snapshots as statutory accounting evidence.
