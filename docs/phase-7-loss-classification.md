# Phase 7: Loss Classification

Classification values: included in original quotation, approved additional client charge, partially recoverable, pending client decision, client rejected, company absorbed, contractually nonbillable, and under investigation.

Rejection is recorded without deleting or changing an actual expense. A rejected ACR is marked company-absorbed; its proposed selling amount is not treated as actual loss. Partial approval stores actual cost and approved amount separately, so unrecovered actual cost is `max(actual cost - approved amount, 0)` and forgone proposed revenue is `max(proposed amount - approved amount, 0)`. These are different quantities. ACR classification does not post another expense.

Accounting and Finance have classification permissions. Company-absorbed classification requires a final rejection or partial approval decision and a reason. Pending/no-response requests are not automatically rejected. Shared expense allocation is not yet configurable; linked expense costs are treated as whole expense records, with no fractional recoverability split.
