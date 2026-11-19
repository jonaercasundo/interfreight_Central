# IFC AI API Integration Report

**Date:** 2026-10-24 (Asia/Singapore)  
**Scope:** OpenAI integration, Administrator AI settings, and Sales tutorial grounding. No production systems were accessed. No live OpenAI credential was configured, and no real OpenAI request was made.

## 1. Implemented AI configuration

- Added the existing IFC shell page at `/admin/settings/ai`, linked under Administration as **AI Configuration**. The page shows an Administration / System Settings / AI Configuration breadcrumb.
- Administrators can enable or disable the assistant and Sales tutorials, choose a model, enter or rotate a key with explicit confirmation, and remove an Administrator-managed key by typing `REMOVE`.
- Saved settings expose provider, selected model, last test state/timestamps, credential source and a masked suffix. The page never pre-fills or receives the key.
- The responsive page includes connection state, explicit Test Connection action, safe diagnostics, usage totals, and a recent request table. Requests are limited to 20 per minute; configuration writes, deletes and tests have tighter per-route throttles.
- Setting the assistant enabled without an Administrator-managed or environment-managed key is rejected before the setting is saved.

## 2. Credential storage and security architecture

- Migration `2026_11_06_090000_create_ai_configuration_and_usage_tables.php` adds `ai_configurations` and `ai_usage_events`, plus the three `ai.configuration.*` permissions and Administrator grants. It was exercised by isolated Laravel tests; it was **not manually applied** to the persistent local SQLite or development MySQL databases.
- The Laravel model uses Laravel's encrypted cast for `api_key_ciphertext`; a raw database check in tests confirms the stored value is ciphertext. Keep `APP_KEY` in a protected secrets system and include its recovery in key backup procedures. If it is lost or rotated without re-encrypting the stored value, the saved API key cannot be decrypted.
- Browser APIs return only a masked suffix and metadata. Audit records contain no key or key value. Prompts and assistant responses are not stored; usage events keep user, model, status/category, token counts and latency only.
- FastAPI retrieves the credential over `GET /api/internal/ai-credential` using the existing Laravel–Python HMAC signature scheme, timestamp, unique nonce and correlation header. It is rate-limited, `no-store`, production HTTPS-only, and uses Redis-backed atomic nonce claims in production. The raw key is absent from the regular `/assistant` payload. FastAPI holds the retrieved server-side configuration in process memory for up to 5 seconds; after a rotation/removal the old value can remain cached for that short window.
- Development can use the server-only endpoint or an environment-managed `OPENAI_API_KEY` fallback. Production Compose requires an HTTPS credential endpoint reachable from FastAPI and a mounted CA file. The development Compose file routes the internal credential lookup to the Laravel container.
- Session authentication, authorization, and CSRF protections cover browser settings endpoints. The internal credential endpoint does not rely on browser authentication; its signed machine request is required and unsigned access is tested as denied.

## 3. OpenAI API integration

- FastAPI calls the official Responses endpoint `POST https://api.openai.com/v1/responses` with `model`, `instructions`, `input`, `max_output_tokens`, and `store: false`. It parses the response `output` message's `output_text` and records returned input/output token usage. This follows the [OpenAI Responses API reference](https://developers.openai.com/api/reference/resources/responses/methods/create).
- The default is `gpt-5.4-mini`, with `gpt-5.4` and `gpt-5.4-nano` offered in the Administrator selector. The current [GPT-5.4 mini model documentation](https://developers.openai.com/api/docs/models/gpt-5.4-mini) lists the Responses endpoint. A configured model is tested with a minimal request only after an Administrator presses **Test connection**.
- Calls use bounded connect/read timeouts, at most one retry for timeout/transient server and rate-limit responses, output-token caps, and safe categories for invalid key, unavailable model, quota/billing, rate limit and service outage. Provider error text and credentials are not returned to the browser.
- Tests use synthetic keys and mocked OpenAI responses only. No claim of a successful external OpenAI connection is made.

## 4. Sales tutorial integration

- The signed-in Laravel user remains the authorization source. FastAPI receives only permission names and page/tutor content already filtered by `TutorialKnowledgeBase`; it receives a selected inquiry's persisted status only after Laravel verifies inquiry-view permission and ownership.
- The system prompt treats the question as untrusted, limits answers to the verified context, forbids record mutation and instructs the model not to invent absent facts. Tutorial details include authorized pages, steps and prerequisites. English and Filipino/Taglish are supported.
- Inquiry status, approval explanations and shipment availability continue to use deterministic Laravel logic. Forecasts are distinguished from operational shipments, and capacity answers only use verified register entries. If FastAPI is unavailable, deterministic status/capacity logic remains available, and verified tutorial steps are the fallback where applicable.
- The tutorial catalog is independently permission-filtered and can be disabled by an Administrator. Future module names remain planned only; no Operations, Billing, Accounting or other module knowledge was fabricated.

## 5. Permission enforcement

- Page access requires both the `Administrator` role and `ai.configuration.view`. Mutations and key deletion require `Administrator` plus `ai.configuration.manage`; live connection testing requires `Administrator` plus `ai.configuration.test`.
- Laravel routes enforce session auth, active account, password session, mandatory password change, CSRF, permission checks, and rate limits. FastAPI verifies the existing internal bearer token for ordinary assistant calls and verifies HMAC for credential retrieval and explicit connection testing.
- Frontend navigation filters AI Configuration by `ai.configuration.view`. Removing that permission hides the navigation entry, while server authorization still denies direct access.

## 6. Connection testing and usage monitoring

- The Test Connection button performs one minimal signed Laravel-to-FastAPI call, which then makes one bounded OpenAI request. It updates last-tested status and last-successful timestamp and writes a usage/audit event without the key or prompt.
- An invalid-key, missing-model, quota, rate-limit or service error was not exercised against the external service because no approved credential was present. Each category was exercised using mocked FastAPI/OpenAI responses.
- Usage monitoring reports the most recent 30 days of request totals, success/failure, token counts, model, safe error category and latency. It does not calculate cost because account pricing/discounts cannot be inferred reliably from token counts alone.

## 7. Automated test results

| Check | Result |
|---|---|
| Full Laravel suite (`php artisan test`) | **129 passed, 5 skipped, 0 failed; 1,366 assertions; 538.06 seconds.** Runs under the configured isolated PHPUnit database. Two optional AI live integration tests require `AI_E2E_URL`/`AI_E2E_TOKEN`; two Redis queue tests require the host PHP Redis extension; the live ClamAV test requires `IFC_CLAMAV_HOST`. |
| Focused Laravel regression suite | **51 passed, 0 failed; 541 assertions** (`AiConfigurationTest`, `AssistantTutorialTest`, `IdentityAndAdministrationTest`, and `ShipmentApiTest`). |
| Python FastAPI suite | **27 passed**; 1 upstream Starlette/httpx TestClient deprecation warning. OpenAI calls use `httpx.MockTransport`; no external API calls. |
| Frontend tests | **10 passed**, including permission-filtered AI Configuration navigation. |
| Vite production build | Passed, 34 modules. Vite reports the existing application bundle exceeds 500 kB minified. |
| Laravel Pint | `vendor/bin/pint --test` **passed**. |
| Composer audit | No security advisories found. |
| npm audit | 0 vulnerabilities. |
| Development Compose config | Parsed successfully using a synthetic internal test token. |
| Production Compose config | Parsed successfully using synthetic-only required values. Images/services were not rebuilt or deployed for this task. |
| Persistent database migration | Not run. Laravel feature tests use isolated test configuration; existing local SQLite/MySQL data remains untouched. |
| Real OpenAI connection | **Not run**; no approved key configured, and no administrator explicitly initiated a real test. |
| Real FastAPI-to-Laravel credential retrieval | Mocked/isolated tests cover signature construction/validation, endpoint denial, and credential-safety behavior; a live container-to-container OpenAI workflow was not run in this phase. |

The full Laravel suite completed serially; no persistent application database was the test target.

## 8. Remaining limitations

1. No OpenAI account credential, allowed model access, quota or live provider response was available to verify billing/project access. An Administrator must configure a key and explicitly test it in a non-production environment.
2. Production APP_KEY, HTTPS certificate/CA trust, Redis availability, secrets manager, and the routed credential endpoint were not validated against a deployed infrastructure environment.
3. The runtime credential cache allows up to 5 seconds before a changed or removed key is refreshed by ordinary assistant calls; explicit connection tests force a fresh retrieval.
4. AI output is advisory and may still be wrong despite grounding instructions. Deterministic IFC data answers remain authoritative; users must review workflow guidance before action.
5. A generic provider/model field accepts custom model identifiers through the backend; availability is confirmed only by an explicit test using the configured account.
6. OpenAI usage costs and account limits remain administered in the OpenAI account. IFC reports tokens, not billed cost.

## 9. Administrator setup instructions

1. In an isolated development/staging database, apply migrations and run the access-control seeder through the approved deployment workflow. Do not seed or migrate a populated production database as part of this report.
2. Confirm the stable Laravel `APP_KEY` is protected and recoverable. Keep OpenAI API access restricted to the intended project and apply account-side usage limits.
3. Sign in with an authorized Administrator and open **Administration → AI Configuration**.
4. Enter the API key, confirm replacement, choose a documented model, leave the assistant disabled until ready, and Save Settings. Enabling without a key is rejected.
5. Enable AI and/or Sales tutorials as approved. Press **Test connection** to perform the minimal live request. Review the safe result and timestamp. The UI never shows the full saved key.
6. For environment fallback, provision `OPENAI_API_KEY` and `IFC_AI_ENABLED=true` only in the protected Laravel runtime environment. For container deployments configure `AI_LARAVEL_CREDENTIAL_URL` to the HTTPS route reachable from FastAPI and mount the trusted CA at `AI_LARAVEL_CREDENTIAL_CA`. Do not place the key in a `VITE_*` variable or frontend build.
7. To rotate the stored credential, enter the new value and confirm. To remove the Administrator-managed value, type `REMOVE`; an environment fallback remains active if configured.

## 10. Readiness recommendation

**CONDITIONAL PASS for code-level development integration; NOT VERIFIED for live OpenAI service use or production readiness.** Encrypted key handling, Administrator-only configuration, signed server-side retrieval, explicit connection testing, grounded Sales context, safe error categories, and mocked automated tests are implemented. Production use remains blocked on a company-approved OpenAI project/key and model entitlement, verified runtime HTTPS/CA/Redis/secrets configuration, successful explicit staging connection testing, and the existing Phase 13–17 financial, statutory, security, operational and UAT gates. No production data was changed and no deployment occurred.
