# Phase 10: Post-closing adjustments

Finance can reopen a closed request with a reason. Reopening appends a review event and changes the current workflow state; the prior snapshot remains unchanged and is retained. The next request uses a higher version number.

This version does not implement materiality classification, correcting journal or billing records, automatic reopening from source changes, or a superseded-snapshot link. Post-close adjustments must continue through the source module's own controlled workflow and should not be represented as finalized profit until those controls are added.
