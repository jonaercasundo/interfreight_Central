# Phase 7: Additional Charge Requests

## Scope

Phase 7 records unexpected shipment costs as separate Additional Charge Requests (ACRs). An ACR is not an actual expense posting, an invoice, or an edit to an accepted quotation. Existing Phase 6 `actual_expenses` remain the cost ledger. When verified expenses are linked, their cost total must match the ACR actual-cost value and currency. Linked costs cannot be recovered by another open or authorized ACR.

ACR references use `ACR-YYYY-000001` and a locked year sequence. Requests can be saved as drafts, submitted with evidence, internally reviewed, prepared for manual client contact, and recorded with client response evidence. Rejected requests remain available for loss classification and history.

## Categories and values

Categories: shipping-line charges, port storage, demurrage, detention, extra trucking, additional delivery, fuel surcharge, customs, inspection, rebooking, warehousing, handling, documentation, waiting time, and other. Amounts use decimal database columns and a three-letter currency. No automatic exchange rate conversion is configured. Actual cost and proposed client amount are independently entered.

## Records and controls

The request, expense links, private evidence metadata, append-only ACR events, and commercial-change authorizations are stored separately. Uploads use Laravel's private `local` disk and authorized download routes. Reference, shipment, status, decision evidence and actor details are retained. Phase 7 does not create invoices or debit/credit expense records.

## Operational limitations

One ACR currently records aggregate actual and proposed amounts. It can link multiple verified expenses, but client partial approval and commercial authorization are at the request level rather than line level. Document print preview, configurable follow-up/escalation rules, amendments/reversals, and analytics charts are not implemented. Users must manually transmit client requests through their approved channel; the system sends no external messages.
