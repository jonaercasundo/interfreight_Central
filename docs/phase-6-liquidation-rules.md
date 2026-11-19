# Liquidation rules

Outstanding advance = verified disbursement − verified advance-funded expenses − verified returns, floored at zero. Liquidation lines must reference verified expenses from the same shipment, department, and currency. Cumulative pending and verified allocations cannot exceed an expense's amount, preventing the same expense from funding multiple advances twice.

If verified eligible expenses exceed the outstanding advance, the excess is recorded as `reimbursement_due`; it does not create negative advance outstanding and does not itself cause payment. Expense verification and liquidation review must be performed by someone other than the requester/preparer.

Cash advance principal is not recorded as an expense. The underlying verified cost is recognized once in `actual_expenses`.
