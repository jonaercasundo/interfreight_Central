# Phase 8 Accounting Handover

`billing_accounting_outbox` establishes unique invoice and event keys for a future idempotent receivables handover. No general ledger, collection, payment, bank reconciliation, or authoritative receivable service is currently connected. The dashboard/detail show “Pending Accounting Integration” and collection status “Unavailable”. No payment, balance, posting, or settlement event is fabricated. Implement event publication only after a real issuance provider writes an immutable issued event.
