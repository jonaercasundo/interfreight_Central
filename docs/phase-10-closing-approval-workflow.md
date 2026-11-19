# Phase 10: Closing approval workflow

Finance prepares and submits a reasoned close request. Executive approval/rejection/correction is separately recorded with a required comment and cannot be performed by the preparer. Finance finalizes an approved request in a database transaction after blockers are rechecked. Row locks and optimistic `lock_version` values prevent stale transitions. Every transition also writes an application audit event.

The close operation is fail-closed while a source blocker exists. Because revenue recognition is not configured in this application version, the current close path correctly remains blocked until the prerequisite accounting policy and verified source event are implemented.
