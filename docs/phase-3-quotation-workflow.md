# Phase 3 Quotation Workflow

1. Sales prepares an inquiry and forecast. A quotation cannot be started without an approved cost estimate linked to an active forecast.
2. Sales prepares distinct client selling lines and terms, previews the projected margin, and submits the draft.
3. Accounting and Finance complete configured quotation review steps. Review remarks, stage, actor, action, and timestamp are recorded in `quotation_approval_steps`.
4. An authorized final approver approves only after configured review stages are complete. The default review sequence is configurable through `IFC_REQUIRED_QUOTATION_REVIEWS` (default `accounting,finance`). Self-approval can be prohibited through `IFC_PROHIBIT_SELF_APPROVAL`.
5. Sales issues the internally approved quote. Issuing does not mean client acceptance.
6. Authorized Sales records a separate client acceptance or rejection with representative, version, response time, evidence reference, and remarks.
7. Acceptance creates a versioned commercial baseline snapshot. Accepted terms are not edited in place; a change creates a new quotation version and requires the applicable approvals and acceptance.

The client print view includes client details, scope, selling lines, tax, amount, and client terms. It deliberately excludes supplier costs, internal notes, and internal margin. Phase 3 does not create invoices, collect payment, process expenses, or complete shipments.
