# Isolated Restore Drill

**Status: NOT RUN.** No verified backup artifact or disposable MySQL/SQLite recovery target was available. No non-disposable database was overwritten. No restore, recovered row-count comparison, foreign-key check, document-link verification, application launch, RTO, or recovered-data cutoff measurement is claimed.

To complete: obtain an approved verified backup, provision a newly created isolated disposable QA database and isolated document storage, restore only there, validate representative records and referential integrity, launch the recovered application with non-production secrets, run smoke tests, and record measured recovery duration and cutoff. Approval is required before any restore into a non-disposable environment.
