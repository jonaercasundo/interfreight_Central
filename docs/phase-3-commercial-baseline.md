# Phase 3 Commercial Baseline

`commercial_baselines` stores one immutable snapshot per accepted quotation version. It captures the client-facing quotation header and selling lines, the linked cost estimate snapshot, approval history, and acceptance evidence. The application model blocks ordinary Eloquent updates and deletes; baseline activation is changed by a narrow query when a later accepted revision replaces the active version for the inquiry.

The active baseline is the agreed commercial reference for future operations. Client acceptance is separate from internal approval and issuance. Rejected, expired, or issued offers do not create a baseline. A later accepted revision deactivates the previous baseline without rewriting its snapshot. The quotation and its versions remain available as history.

Future change requests must carry a separately authorized client-approved amount. Unapproved company costs must remain internal exposure and must never be silently added to an agreed client price. Operational billing and expense liquidation are outside this phase.
