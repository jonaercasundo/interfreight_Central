# Phase 8 Invoice Data Model

- `billing_invoices`: internal `BIL-YYYY-######` reference, client/shipment/quotation/baseline links, version, currency, terms, due date, workflow state, computed amounts, creator/reviewer, and an issued snapshot slot.
- `billing_invoice_lines`: source type and ID, authorization reference, immutable source snapshot, authorized and previously invoiced amount, billed amount, currency, and quotation tax fields where known.
- `billing_invoice_approvals`: append-only review actions and comments.
- `billing_invoice_events`: append-only workflow/document events and before/after state.
- `billing_invoice_documents`: private storage path, original display name, MIME, size, SHA-256, version, and uploader.
- `billing_invoice_sequences`: per-year transactional internal reference sequence.
- `billing_exceptions`: retained open/resolved exceptions and resolution metadata.
- `billing_accounting_outbox`: unique invoice/event keys for future idempotent handover.

Source authorization remains in Phase 3/7 tables; no shadow authorization table is created. Foreign keys restrict deletion of referenced commercial records. Internal reference numbers are not official statutory invoice numbers.
