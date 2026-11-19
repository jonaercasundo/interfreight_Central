# RBAC and Security Test Report

Automated identity/administration tests exercise login session regeneration/invalidation, inactive user denial, role and permission provisioning, direct route authorization, sidebar filtering, role assignment protections, least privilege, audit redaction, privileged-account protection, and permission update validation/auditing. Shipment, budget, expense, commercial, billing, collection, closing and AI tests also assert anonymous or unauthorized responses for relevant endpoints.

These are test assertions, not a complete 9-role × route × record ownership permission matrix. No browser role-switching/IDOR campaign or all-role document download matrix was executed. No confirmed authorization bypass was observed in the executed suite; untested combinations remain **unverified**, not passed. Administrator business approval restrictions are covered for selected workflows, not exhaustively for every mutation.

Detailed gates are in [security assessment](security-assessment.md) and [approval security](approval-security-report.md).
