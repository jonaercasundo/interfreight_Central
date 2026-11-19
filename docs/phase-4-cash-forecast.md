# Phase 4: Cash Forecast

The cash requirement view reuses expected funding demand from shipment forecasts and approved estimate lines. Current source records have forecast month but no expected payment due date. Therefore the interface identifies forecast month as demand timing only and displays due dates as `Not recorded`.

There is no verified cash position, bank balance, payment, disbursement, or cash-advance source in Phase 1–3. The module displays `Not recorded` for cash position and does not report a cash gap. Commitment, actual expense, cash advance, liquidation, and payment states are `Not integrated`.

When these source modules become available, integrate their authoritative records and use payment due dates rather than shipment dates. Compare forecast needs to approved allocations for funding coverage. Calculate a cash gap only against verified available cash/funding sources. Do not subtract actual expense and payment twice, and reconcile cash advances to their underlying expenses before computing consumption.
