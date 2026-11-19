# Phase 8 Billing Architecture

## Authority

The Phase 3 accepted `commercial_baselines` snapshot is the immutable authority for original quotation scope. Invoice rows retain the quotation, baseline, version, source line identity, and a copy of the displayed source data. Phase 7 `commercial_change_authorizations` are the only additional-charge source. Actual expense rows, internal approvals, pending client decisions, and rejected requests are never read as billable lines.

## Workflows

Billing eligibility permits draft preparation from a non-cancelled shipment linked to a valid client, quotation, and baseline whose version and selling lines match. There is no configured service milestone/POD policy or billing hold/dispute field in the current schema; the UI reports this and does not claim delivery completion. Drafts are separate from approvals, statutory issuance, collection, and settlement. Accounting and Finance permissions gate reviews; a preparer cannot review their own invoice. Administrator role alone does not meet the Accounting/Finance review check.

## Explicit limits

The current environment does not contain verified seller tax registration, VAT configuration, official number allocation, statutory invoice template requirements, or an accounting/collection integration. `IFC_BILLING_LEGAL_ISSUANCE_VERIFIED` defaults to false. Even when enabled, the issue endpoint remains closed pending an issuance provider implementation. Draft HTML is labeled non-statutory, not a customer tax invoice. Payment and outstanding balances display unavailable. No external delivery or accounting posting is represented.
