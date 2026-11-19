# IFC Phase 1 test report

Run date: 2026-10-23 (client timezone Asia/Singapore).

## Verification

| Check | Result |
|---|---|
| Laravel feature suite | 25 passed (111 assertions); 2 optional live communication tests skipped in the default run (protected diagnostic and assistant proxy). Both passed in the separate live local handshake. |
| Python pytest | 11 passed, with one Starlette/httpx deprecation warning. Includes health, assistant auth/input, signed diagnostic, invalid key, body tampering, expiry, malformed request, and replay. |
| Vite production build | Passed (`npm run build`). |
| Laravel Pint | Passed `vendor\\bin\\pint --test`. |
| PHP syntax and route checks | Passed syntax checks for the application files; route list and migration status loaded successfully. |
| Composer validation | Passed after widening the Spatie constraint to `^8.0`. |
| Laravel HTTP smoke | Passed: `/login` returned 200 and unauthenticated `/dashboard` redirected (302) on the local development server. |
| MySQL-specific migration run | Not run; local migration/test DB is SQLite. Migrations use MySQL-compatible Laravel schema APIs and still require staging verification against MySQL 8. |
| Browser / visual interaction | Not performed. Build and server-side feature tests are not browser verification. |
| Live HTTP Laravel-to-FastAPI handshake | Passed both signed diagnostic and assistant proxy checks against a locally started FastAPI process using a temporary test-only shared key. |
| Git diff checks | Unavailable: the supplied directory has no `.git` metadata. Whitespace scan used instead. |

No destructive migration command was used. The local existing SQLite database received additive migrations and the idempotent RBAC catalog seeder; its prior shipment migration/data was preserved. Automated tests use isolated test databases. The existing ignored `.env` integration credential was not aligned/configured for the first live attempt; the end-to-end code path passed after supplying an isolated temporary matching key. Configure matching `AI_SERVICE_TOKEN` values for normal local use.

The initial identity/administration test run surfaced an inactive-account middleware bug on stateless API requests (attempted session access). The middleware was corrected to invalidate sessions only when a session is attached; the full suite passed after that fix.
