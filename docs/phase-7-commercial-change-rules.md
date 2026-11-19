# Phase 7: Commercial Change Rules

- The accepted Phase 3 quotation and `commercial_baselines` rows are not changed by ACR actions.
- The original quotation ID, accepted baseline ID/version, approved amount, currency, evidence, client representative and effective date are snapshotted in `commercial_change_authorizations`.
- Only approved/partially approved ACRs with verified decision evidence create a billing authorization. The billing API is read-only.
- Pending, rejected, not-requested, withdrawn, and no-response values never become billing-eligible.
- A unique authorization per request prevents duplicate billing authorization. Authorization versions are retained for the request.
- Phase 7 does not generate or mutate invoices, tax postings, or quotation selling lines.
- FX conversion and currency aggregation are not configured. Billing eligibility is returned in the source currency.
