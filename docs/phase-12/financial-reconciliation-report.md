# Financial Calculation and Reconciliation Report

Existing tests include decimal-safe commercial formulas and rounding, budget allocation caps/variance, estimate-cost funding basis, approved/rejected charge eligibility, billing, collection allocations/reconciliation/credit/reversal, and closing behavior when recognized revenue is unknown. See the Phase 3–10 reports and [regression matrix](regression-test-matrix.md).

No independent full-ledger oracle covering all 15 requested invariants was run in this QA pass. In particular, production-currency/FX policy, tax setup, cross-ledger actual expense and advance treatment, duplicate allocations under concurrent transactions, immutable closing snapshot tampering, and all rounding boundaries require additional fixtures and target-engine validation. Existing tests do not establish statutory invoice readiness. There is no evidence of a verified material financial discrepancy in this run; the untested rules are release blockers until independently evidenced.

**Result: PARTIAL; not a financial audit or reconciliation sign-off.**
