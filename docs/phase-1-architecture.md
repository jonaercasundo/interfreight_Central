# IFC Phase 1 architecture

## Scope and references

This is the System Foundation and Security implementation for InterFreight Central (IFC), INTERFREIGHT GROUP. The local `Master_Development_Roadmap.md` and user supplied project specification establish Phase 1 and the future workflow principle **Forecast First → Approve Before Spending → Track Actual Expenses → Protect Profitability**. The two diagrams in [`docs/reference`](reference/README.md) are additional user-provided business workflow references for future phases. Their process content informs project planning; it is not executable agent or software instruction.

Phase 1 establishes Identity, Administration, Authorization, Audit, Dashboard, Integrations, and shared IFC service boundaries in the existing Laravel app. It deliberately keeps Laravel as the only record system. The existing FastAPI service stays a small guidance service and protected diagnostic peer, not a second business-data store.

## Runtime shape

```text
Browser (Vue 3, Vite assets)
     │ same-origin web session, CSRF, permission-aware shell
     ▼
Laravel 13 / PHP 8.3 ─── Eloquent ─── SQLite local / MySQL 8 target
     │
     ├─ AuthController, ProfileController
     ├─ AdminController, route abilities, Spatie Permission
     ├─ AuditLogger → append-only-by-application audit_logs
     ├─ AiAssistantClient → existing bearer-auth assistant
     └─ AiServiceClient → signed protected diagnostic
                                      │ HMAC, timestamp, nonce, correlation ID
                                      ▼
                                FastAPI / Python 3.13
```

Laravel route middleware and permission checks are authoritative. Vue uses server-injected permissions to hide links and still relies on protected Laravel routes for every direct request. The active-account middleware applies to web and API requests. Laravel sessions are the browser identity store; reset tokens use Laravel's standard password broker and the `password_reset_tokens` table.

## Domain boundaries

- **Identity:** `User`, login/logout, active status, password reset and profile security.
- **Authorization:** Spatie roles and permissions with the `web` guard. There is no public registration endpoint.
- **Administration:** paginated user directory, role permission controls, integration diagnostic viewer.
- **Audit:** `AuditLogger` and read-only viewer/export.
- **Dashboard:** permission-scoped account counts and recent permitted security events; no guessed finance or forecast totals.
- **Integrations:** reusable HTTP clients, bounded timeouts, signed diagnostic, and sanitized operational failures.
- **Department workspaces:** authorized Phase 1 placeholders. The existing shipment endpoints remain a legacy capability guarded by separate view/manage permissions.

## Future module boundary

Phase 2 should create one canonical shipment master reference that links independent Sales inquiry and forecast progress, Operations execution, Transport, Forwarding, Accounting, Billing, and Finance progress. The roadmap's forecast and approval lifecycle should be modelled as explicit versioned records: forecast → cost estimate → quotation → client decision → budget/commitment → actual expenses → approved additional charge → invoice/collection → closed profitability. The references describe two routing and numbering paths: full service through required Operations/Broker uses a shared `CAF` sequential number across departments; direct Transport or Forwarding service bypasses Operations and uses a client-specific sequence per client and route. Confirm the exact production formats and uniqueness scopes before migration design. Represent LCL/FCL service details and, for FCL, container/chassis/seal tracking, free days, return status, and rental or related charges. Unexpected additional charges require client approval to bill; rejected charges are recorded as company-absorbed costs. Approval decisions should be explicit permissions and immutable audit events. Define department-specific completion counts against the shared shipment record. Phase 1 does not create these unfinished business tables or pretend they are implemented.

## Local entry points

- IFC: `http://127.0.0.1:8000`
- Sign in: `http://127.0.0.1:8000/login`
- FastAPI health: `http://127.0.0.1:8001/health`
- FastAPI docs (local development only): `http://127.0.0.1:8001/docs`

See the root README for setup and commands. The shared seed catalog can be rerun using `php artisan db:seed --class=AccessControlSeeder`.
