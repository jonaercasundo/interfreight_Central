# Cash advance workflow

1. An assigned Operations, Transport, or Forwarding requester drafts an advance against an approved same-department, same-currency allocation and matching category.
2. The requester submits it. Allocation capacity is checked against actuals and other reservations.
3. A different authorized Accounting reviewer marks it under review.
4. A distinct Finance approver approves or rejects it. Approval moves to `disbursement_pending` and never means that cash moved.
5. Accounting records one disbursement for the approved amount with payment source and reference. A different authorized Finance actor verifies that record before the advance becomes `disbursed`.
6. The requester submits expense liquidation lines or records a return. A different authorized reviewer verifies them.

No external payment is initiated. Disbursement references describe an existing authorized payment source; verify that evidence before treating funds as disbursed.
