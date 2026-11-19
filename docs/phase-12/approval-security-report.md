# Approval Workflow Security Report

Automated coverage includes budget reviewer/allocator separation and cap, approved budget locking, quotation workflow and immutable baseline behavior, additional-charge evidence gating and rejection, privileged role change checks, and financial closing authority restrictions. Tests exercise server-side endpoints, not just UI states.

The requested complete matrix—self-approval for every workflow, simultaneous/duplicate approval, approval after concurrent record edits, retry after rejection, every invalid transition, evidence tampering, and closing approval under transaction races—was not executed. No concurrency environment for the target database is available. Do not treat these gaps as passed. See [concurrency report](concurrency-test-report.md).
