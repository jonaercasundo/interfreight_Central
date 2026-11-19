# Phase 9 — Collection and Accounting

## Implemented scope

- Issued invoices are the only source for accounts receivable; draft invoices never count.
- Payment receipts support partial allocation, multiple invoice allocations through the API, payment method/provider/bank reference metadata, and supporting evidence references.
- Accounting enters receipts as pending. Finance separately reconciles or rejects them, with required notes, actor/timestamp history, and a no-self-reconciliation rule.
- Pending allocations reserve invoice capacity. Rejected receipts release it. Verified allocations reduce invoice balances.
- Finance can reverse a verified receipt through a separate audited action. Original receipt, allocations, and reconciliation events remain stored. This accounting reversal does not initiate a refund or bank transfer.
- Verified excess amounts remain unapplied customer credits and can be allocated by Accounting to another issued invoice for the same client and currency. This does not initiate refunds.
- Finance can enter bank statement credits manually, pair exact amount/currency matches with receipts, then independently complete receipt reconciliation. This is not a bank connection or a claim of external bank confirmation.
- The customer ledger shows issued invoice charges, verified allocations, unapplied credits, opening balance (when a start date is supplied), and closing balance by currency.
- Aging can be requested with an `as_of` date and uses reconciliation effective dates for collections. The UI includes Accounts Receivable, payment history, payment reconciliation, ledger, aging, and follow-up views.
- Internal follow-ups are tied to issued invoices and record assigned user, contact method, notes, promised date/amount, next action, and status. No external messages are sent.
- Dashboard totals include receivables by currency, verified collections this month, unapplied credits, pending receipts, overdue invoices, partial balances, and fully settled invoices.

## Access boundaries

| Role | Access |
| --- | --- |
| Accounting | View receivables/history; record receipts; allocate credits; view/manage follow-ups |
| Finance | View receivables/history/follow-ups; enter/match statement credits; reconcile, reject, or reverse receipts |
| Billing | Read-only collection monitoring and payment history |
| Executive | Read-only collection monitoring and follow-up visibility |

Route middleware and controller checks enforce permissions. Finance decisions cannot be made by the receipt recorder.

## Data and financial controls

- Amounts are stored as decimals and compared in integer cents.
- Receipt and statement transaction references have duplicate checks; statement row keys are unique.
- Bank matching requires exact currency and amount. When the receipt supplied a transaction reference, it must match the statement reference. Matching alone does not verify the payment; Finance still makes the separate decision.
- All receipt, allocation, statement, and follow-up links preserve historical rows on deletion. State changes and allocation events are written to the existing audit log and collection event history.
- Currencies are not combined. Shipment profitability and revenue remain outside this collection module.

## Known boundaries

- Phase 8 has no implemented credit-note or issued-invoice adjustment source workflow, so invoice amounts are not changed here. Credit notes, write-offs, disputes, reversals/reallocations, and immutable payment reversal accounting still need an authorized source workflow.
- No bank account connection, file import, automatic uncertain matching, refund, or general-ledger journal posting is implemented.
- Aging uses effective receipt reconciliation dates, but there is no full bitemporal accounting journal; historical corrections/reversals cannot be reconstructed until those workflows exist.
- The page provides operational summaries, not all requested monthly trend charts, collection rate/average days, scheduled notifications, printable customer-period selection, or filtered export reports.
- Statutory invoice issuance remains disabled by the Phase 8 safeguard. Only existing issued invoices can become receivables.
