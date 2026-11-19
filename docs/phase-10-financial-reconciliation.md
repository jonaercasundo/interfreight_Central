# Phase 10: Financial reconciliation

The closing detail distinguishes billed, collected, receivable, and verified expense facts. A collection counts only allocations attached to reconciled receipts; reversed receipts no longer count. Unreconciled payment allocations and unresolved cash advances block their respective completion dimension. Verified expenses are sourced from `actual_expenses`; advance disbursement is not added as an expense.

Unresolved claims, inconsistent currency, missing baseline, unissued invoice workflows, unsettled additional-charge decisions, and unresolved operational exceptions produce human-readable blockers. Source records remain authoritative; this feature does not create journal entries, payment confirmations, expenses, FX rates, or charge approvals.
