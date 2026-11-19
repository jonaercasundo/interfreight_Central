# Phase 7: Client Approval Workflow

1. An assigned operating department creates an ACR draft and attaches cost or carrier evidence.
2. The requester submits it. At least one evidence item is required.
3. An authorized internal reviewer approves for client request, rejects, or requests revision. Requesters cannot approve their own ACR.
4. Sales prepares the client request and records follow-up information. This only records preparation; it does not email or message the client.
5. Sales uploads client decision evidence (`email_approval`, `client_letter`, or `signed_decision`). Accounting or Finance verifies that evidence; the uploader cannot verify their own upload. Operating cost receipts alone do not satisfy this approval evidence gate.
6. Sales records approved, partial, rejected, or no-response status with remarks and representative details. Approval requires verified evidence and a separate commercial authorization permission.
7. Approved or partially approved decisions create a versioned, read-only commercial change authorization for the actual approved amount. Rejection creates no authorization and the actual cost remains.

No-response remains pending and has no decision timestamp or billing eligibility. A partial approval amount must be positive and less than the proposed amount. Full approval must equal the request amount. A missing, rejected, or pending evidence item cannot authorize billing.

Sales list/detail and client actions are limited to clients owned by the authenticated Sales user. Accounting, Finance, Executive, and Administrator access follows their dedicated permissions.

The current verifier verifies evidence; the workflow does not yet implement a separate second-person verification of the final recorded client decision itself. This remains a control gap for company policy review before production adoption.
