# Phase 8 Billing Eligibility

The eligibility service requires a shipment with client, quotation, and baseline links; an existing accepted baseline for the same quotation/version; and current quotation selling lines whose descriptions and totals match the accepted baseline snapshot. Canceled and archived jobs are excluded. A mismatch blocks the entire candidate. Currency differences between authorized sources block draft creation.

Only positive remaining quotation-line amounts and Phase 7 client-approved authorization records with linked approval evidence are offered. Consumed amounts are determined from issued invoice lines. Pending/rejected/withdrawn/unverified additional-charge requests have no authorization row and cannot enter the register. No expense-ledger query is used.

The data model has no service-specific invoice milestone policy, POD-required flag, client hold, or dispute fields. The interface identifies these as unconfigured; it does not fabricate operational completion. Draft preparation is based on accepted commercial authorization, with official issuance separately blocked until policy and legal configuration exist.
