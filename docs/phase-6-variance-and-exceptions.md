# Variance and exception policy

Actual-to-estimate variance compares verified actuals against approved estimate lines in the same currency. A missing approved estimate is `N/A`; the system does not invent a zero baseline. Zero approved estimates do not produce a percentage. Verified adjustments and reversals affect actual totals.

The initial exception detector identifies duplicate invoice candidates, missing/unverified receipts, unfunded expenses, rejected client charges, and actual totals above an approved estimate. It does not infer intent. No company threshold is configured, so any positive comparable overrun is surfaced as a review candidate. Additional policy checks can be added when approved thresholds are supplied.

Internal expense verification is separate from client approval. A client decision requires Finance permission and verified supporting-document evidence. Rejected client charges remain actual costs and are marked nonrecoverable.
