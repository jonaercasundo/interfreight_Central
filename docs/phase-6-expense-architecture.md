# Phase 6 expense architecture

`actual_expenses` is the authoritative operational cost ledger. Each expense belongs to one Phase 5 shipment and department, has one currency and category, and can link to an approved Phase 3 estimate line and Phase 4 budget allocation. Departmental views are scoped by existing shipment assignments; Accounting and Finance access follows explicit permissions.

The lifecycle is draft, submitted, verified, or correction requested. Verification recognizes an incurred cost; it does not prove payment or client approval. Verified history is corrected through append-only adjustment/reversal records. Receipts are stored on Laravel's private `local` disk and served only through an authenticated route.

Cash advance requests are separate from expenses. A verified disbursement is required before liquidation. A liquidation associates eligible verified expense amounts to one advance and checks aggregate use against each expense. Verified returns also reduce the balance. All monetary arithmetic is decimal and currency groups remain separate.

Phase 1–5 do not provide an authoritative commitments ledger, configured FX basis, malware scanning service, company overrun threshold, or payment transfer integration. Such values are reported as unavailable; no payment is initiated by this feature.
