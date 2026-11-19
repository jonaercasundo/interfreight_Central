# InterFreight Central — Phase 17 Operational and UAT Readiness

**Date:** 2026-10-24 (workstation local time)

**Scope:** Isolated development/staging hardening and UAT preparation. No production system, database, customer record, or deployment was used.

**Recommendation:** **CONDITIONAL PASS for local development/UAT preparation; FAIL for production readiness.**

Phase 17 added live malware-scanner wiring, a fail-closed queue health view, CSP enforcement, browser-based smoke coverage, and department UAT materials. Real ClamAV clean/infected signature handling, browser flows and responsive widths, Redis-backed replay behavior, and a disposable MySQL lock probe were exercised. Some broader production-grade scenarios remain incomplete. The full Laravel MySQL suite completed during this phase. This report does not claim human acceptance.

## 1. Completed security hardening

- Added a restrictive Content-Security-Policy through Laravel's global security headers. It limits scripts, connections, images, fonts, frames and objects; permits only the local application plus the specifically used Google Fonts endpoints and inline styles. A feature test verifies the policy header on the login page. A Chromium run loaded the application without blocking required assets.
- Added bounded retries for transient ClamAV scan errors. The scan adapter fails closed: infected files, missing configuration and persistent scan errors remain quarantined and do not become trusted documents.
- Added an authorized queue-health endpoint requiring the existing `integrations.status.view` permission. It returns aggregate backlog/failed-job counts, not payloads or customer/job contents. Integration Health displays available, checking and unavailable states.
- Added HTTP-session middleware to the authenticated dashboard API route group after Chromium exposed a real 401 on `/api/dashboard`. The browser now receives the dashboard data under the Laravel login session; a route-middleware feature test and browser run cover this.
- Existing login, RBAC, owner scoping, CSRF and private document authorization remain in force. Phase 17 added tests for quarantine URL denial and unauthenticated dashboard API rejection. Broader adversarial penetration testing, forged-CSRF campaigns, XSS payload fuzzing, and IDOR fuzzing were not performed.
- Dependency checks: Composer reported no advisories; npm audit reported 0 vulnerabilities. No container scanner (Trivy, Grype or Docker Scout) was available, so image vulnerability scanning is outstanding.

## 2. ClamAV verification

- Added ClamAV to the isolated development/staging Docker configuration with private container-network access, persistent signature storage, and a health check. The scanner port is not published to the host.
- Connected the existing `ClamAvMalwareScanner` adapter through configuration. Scanning uses bounded timeouts and retry settings. No scanner or unscanned result is accepted as clean.
- Ran the adapter against the actual isolated ClamAV service using a harmless standard EICAR antivirus test signature and a benign clean file: **clean file → `clean`; EICAR → `infected`**. The EICAR test string is a harmless detection fixture, not executable malware.
- Feature tests cover clean processing, infected/error/not-configured quarantine, retry limits, and denial of trusted access. The public quarantine URL check is denied. The quarantine remains on private storage.
- Not verified: production signature update operations/alerts, scanner cluster failover, administrative quarantine review/retention, and malware scanning at production load. Phase 17's `LiveClamAvIntegrationTest` is opt-in and was not run through PHPUnit; the live adapter was verified directly against ClamAV, with behavior-specific Laravel tests using controlled scanner adapters.

## 3. Redis and queue reliability

- The isolated Phase 17 Redis service was checked with `PING` (`PONG`). Laravel's Redis cache clear path and Redis queue worker had already been exercised in Phase 15; Phase 17 ran synthetic queue reliability tests against Redis and MySQL.
- Synthetic job checks cover first-attempt failure, delayed retry/backoff, `failed_jobs` persistence, retry recovery, and duplicate-delivery idempotency. The focused PHP-image run passed **2 tests / 10 assertions** against Redis; the host-PHP full suites skip the Redis-specific worker case because that host lacks the Redis extension. No customer or financial notifications were sent.
- Python replay protection was verified against shared Redis. Separate Python processes claiming the same nonce produced **first `true`, second `false`**. Signed endpoint checks returned **200** for a valid request and **401** for duplicate nonce, invalid signature, and expired request.
- A controlled Redis outage returned **503** (fail closed); after Redis restarted, the same Python client successfully claimed a fresh nonce. Redis was returned to `PONG` afterward.
- Added a permission-filtered aggregate queue backlog/failure monitor to Integration Health.
- Not verified: a whole Compose worker service restart/recovery under pending load, Redis failover/HA, high contention, alert delivery, or operational retry/backoff tuning. Queue retry behavior was verified with synthetic one-shot workers and isolated test queue state.

## 4. MySQL concurrency and performance

- Used the isolated Phase 17 MySQL 8.4 service and synthetic-only schemas. Existing SQLite data, Phase 15's populated development schema, and production databases were not migrated or altered.
- Ran an eight-client `SELECT … FOR UPDATE` lock serialization probe on a disposable `lock_probe` row. **8 parallel transactions completed in about 1.5 seconds; final counter was 8.** This confirms basic lock serialization only; it is not an application-level shipment/invoice/payment race benchmark.
- MySQL `EXPLAIN` on the owner-scoped client-list query used the existing `clients_sales_owner_id_company_name_index` (`ref`, covering/index use). Prior Phase 15 migration inventory remains the schema baseline; Phase 17 did not add schema migrations.
- Full application-level duplicate shipment creation, invoice source consumption, financial approval, and payment allocation under concurrent request races were not stress-tested. No representative production-sized dataset, full query profile, p95/p99 response baseline, lock contention benchmark, or missing-index audit was performed. Treat performance findings as limited smoke evidence.
- Full Laravel suite on the disposable MySQL 8.4 schema completed: **105 passed, 4 skipped, 0 failed; 1,192 assertions; 2,164.57 seconds.** The suite includes the Phase 16 synthetic lifecycle and financial policy cases. SQLite also completed successfully (see §8).

## 5. Browser E2E results

Added Playwright Chromium E2E coverage using a uniquely named temporary SQLite database and temporary session files. The runner refuses to reuse an existing database path and cleans only its own test artifacts. Browser test data is synthetic and does not persist to the application database.

`npm run test:e2e`: **8 passed** in 55.3 seconds.

Covered User Management Add User dialog and pagination, Roles & Permissions search/selection, Integration Health queue monitor, Sales client search/type filter and distinction between request failure and empty results, plus document-level horizontal overflow at **1440px, 1024px, 768px and 375px**. The browser run also confirmed the previously failing dashboard session API no longer logs a 401.

This is targeted smoke coverage; it does not exercise every department route, every table/filter, all modal validation branches, mobile drawer keyboard accessibility, or a full business workflow in the browser. The UAT users have not signed off.

## 6. Department UAT scenarios

Prepared [`docs/phase-17-uat-scenarios.md`](docs/phase-17-uat-scenarios.md) with synthetic demonstration data and role-specific expected outcomes for Sales, Operations, Transport, Forwarding, Billing, Accounting, Finance and Executive. It includes a successful additional-charge request, a rejected request that remains a company-absorbed cost, independent completion milestones against a single canonical shipment, financial invariants, evidence fields, defect capture and unsigned sign-off templates.

The document is a preparation checklist, not evidence of a completed human UAT. No acceptance result, tester identity or business approval is fabricated. The existing automated synthetic Phase 16 lifecycle test covers inquiry/forecast/estimate/quotation through canonical shipment and department milestones, with close correctly blocked when financial facts remain unknown; it does not issue an official invoice or complete official close.

## 7. Remaining Finance and Legal approvals

- Finance must approve recognized-revenue events, final cost accrual, reporting currency, FX source/date/pair conventions, source-of-truth integration and policy ownership. Financial closing must continue to preserve unknown profitability and block when these are absent.
- Finance and Legal must approve legal entity, tax treatment, invoice numbering, invoice format, corrections, evidence and issuance-provider requirements. Official issuance remains disabled; Phase 17 does not weaken the statutory billing gate.
- Operations must approve a live quarantine review/retention process and managed scanner operating controls before untrusted documents are accepted in production.
- Department owners must execute and sign the UAT cases with approved synthetic/staging access. No department sign-off has occurred.

## 8. Test results

| Check | Result |
|---|---|
| Full Laravel suite, SQLite in-memory | **106 passed, 4 skipped, 0 failed; 1,193 assertions; 604.70 seconds.** Four optional tests require live ClamAV/AI or Redis-extension/container setup; relevant live integrations were exercised separately as described above. |
| Full Laravel suite, disposable MySQL 8.4 | **105 passed, 4 skipped, 0 failed; 1,192 assertions; 2,164.57 seconds.** Skipped cases require opt-in external services or the host Redis PHP extension; live scanner, signed Python/Redis and queue cases were exercised separately where indicated. |
| Browser E2E | **8 passed** (`npm run test:e2e`), 55.3 seconds. |
| Python tests | **18 passed**, 1 upstream Starlette/httpx deprecation warning. |
| Python syntax | `python -m compileall -q python-ai/app python-ai/scripts python-ai/tests` passed. |
| Frontend tests | **8 passed** (`npm run test:frontend`). |
| Production frontend build | `npm run build` passed; Vite 7.3.7, 29 modules transformed. |
| Real ClamAV | Clean benign file classified clean; EICAR classified infected. |
| Real Python/Redis nonce | Duplicate across processes rejected; outage produced 503; after service restart, same client claimed a fresh nonce. Redis `PING` returned `PONG` after recovery. |
| MySQL locking probe | 8 parallel row-lock transactions serialized; counter reconciled to 8 in ~1.5 seconds. |
| `vendor/bin/pint --test` | Passed after import-order fix. |
| `composer audit --no-interaction` | No vulnerability advisories. |
| `npm audit --audit-level=moderate` | 0 vulnerabilities. |
| `git diff --check` | Unavailable: project directory has no Git metadata. A custom trailing-whitespace scan of Phase 17 files passed. |
| Container vulnerability scan | Not run; scanner tooling was unavailable. |

## 9. Files changed

- `.env.example`, `bootstrap/app.php`, `docker-compose.mysql.yml`, `docker-compose.staging.yml`, `package.json`, `package-lock.json`, `playwright.config.js`
- `app/Http/Controllers/QueueHealthController.php`, `app/Http/Middleware/SecurityHeaders.php`, `app/Providers/AppServiceProvider.php`, `app/Services/DocumentScanService.php`
- `config/documents.php`, `config/security.php`, `config/session.php`, `routes/api.php`, `routes/web.php`
- `resources/js/components/IntegrationHealth.vue`
- `tests/Browser/ifc-workflow.spec.js`, `tests/Browser/prepare.php`, `tests/Browser/run.js`, `tests/Feature/LiveClamAvIntegrationTest.php`, `tests/Feature/OperationalHardeningTest.php`, `tests/Feature/QueueReliabilityTest.php`
- `python-ai/scripts/phase17_redis_recovery_probe.py`, `python-ai/scripts/phase17_replay_probe.py`, `python-ai/scripts/phase17_signature_probe.py`
- `docs/phase-17-uat-scenarios.md`
- This report.

**New migrations:** None.

## 10. Outstanding risks and production readiness

1. Finance/Legal policy approval and authoritative integrations are absent; statutory invoice issue and financial close must remain blocked.
2. Human UAT is prepared, not performed. No department acceptance is claimed.
3. MySQL full suite is pending, and the lock probe is only a database-level smoke check. Application race/concurrency tests and production-scale query profiling are outstanding.
4. ClamAV was live-tested locally, but production scanner operations, quarantine review and retention are unverified.
5. Queue health data is available in the UI, but alerts, service failover and real worker restart with pending jobs need an operations drill.
6. Container image vulnerability scanning, full security penetration testing, CSP regression against all deployed assets, production secrets/TLS/monitoring checks, backup restore at representative scale and environment rollback remain open.
7. Browser E2E covers selected screens and four widths, not every module or a complete user-accepted workflow.

**Recommendation: CONDITIONAL PASS for isolated development and UAT preparation; FAIL for production readiness.** Phase 17 provides positive operational verification for local ClamAV, Redis replay outage/recovery, synthetic queue reliability, targeted browser behavior, and a MySQL row-lock smoke probe. Do not deploy, enable statutory issuance, or activate financial closing until policy approvals, broader recovery/security/performance evidence and documented department UAT sign-off are complete.
