# IFC AI Connection Fix Report

**Date:** 2026-10-24
**Scope:** Administrator AI Configuration diagnostics and the signed Laravel → FastAPI → OpenAI test path. No production service was contacted or changed.

## Root cause

The saved administrator API credential is present, encrypted in `ai_configurations`, and the assistant setting is enabled. The configured model is `gpt-5.4-mini`.

The immediate failure occurred before Laravel sent the signed test request. `AiServiceClient::testOpenAi()` requires `services.ai.token`; the local `.env` had `AI_SERVICE_URL` but no `AI_SERVICE_TOKEN`. The service threw a `RuntimeException` with code `0`. `AiConfigurationController` previously mapped unrecognized status codes to `connection_error`, obscuring this local configuration issue.

The signed credential retrieval endpoint also depends on FastAPI having `AI_LARAVEL_CREDENTIAL_URL` and the same private `AI_SERVICE_TOKEN`. Without that URL, the Python service cannot fetch the administrator-managed encrypted credential through the approved server-only endpoint; it falls back to environment-based configuration for ordinary assistant requests.

## Changes made

- Added typed, allowlisted connection failure categories shared by Laravel and FastAPI:
  `invalid_api_key`, `model_not_found`, `model_access_denied`, `insufficient_quota`, `rate_limited`, `ssl_certificate_error`, `connection_timeout`, `dns_error`, `provider_unavailable`, `invalid_provider_response`, and `configuration_missing`.
- Laravel now distinguishes missing/invalid internal service setup from OpenAI provider failures and from network transport errors. It returns only safe messages and category codes; raw provider bodies and exception text are not exposed.
- FastAPI now maps OpenAI Responses API status/error codes and transport failures to the same categories. Its explicit connection-test path requires Laravel credential retrieval, uses the administrator-selected model, and sends one minimal request without a transient retry.
- The AI Configuration page now displays the actionable diagnostic message and its sanitized category.
- Clarified the required Laravel/FastAPI shared token and credential endpoint setup in `.env.example`.
- Added a fresh random development-only `AI_SERVICE_TOKEN` and the local signed credential URL to the ignored root `.env`. The same token is loaded by both local services; its value is not recorded here or in command output.
- Restarted only the local Laravel and FastAPI listeners on `127.0.0.1:8000` and `127.0.0.1:8001`. Both health endpoints returned healthy, and Laravel's signed protected diagnostic authenticated with FastAPI.
- No migration or database schema change was needed. No credential was rotated, removed, or returned to the browser.

## PHP HTTPS and network diagnostics

| Check | Result |
|---|---|
| PHP CLI | PHP 8.3.33; cURL and OpenSSL extensions loaded. |
| cURL / TLS | cURL 8.21.0 with OpenSSL 3.0.21. Peer and hostname verification were explicitly enabled. |
| CA configuration | `curl.cainfo` and `openssl.cafile` both point to the XAMPP CA bundle. |
| DNS | `api.openai.com` resolved to IPv4 addresses. |
| Direct PHP HTTPS probe | `https://api.openai.com/v1/models` completed with cURL errno 0 and HTTP 401. This is the expected unauthenticated response and confirms PHP DNS, TCP, TLS certificate validation, and HTTPS transport. No key was sent. |
| Proxy configuration | No `HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY`, or `NO_PROXY` environment variable was present in the inspected shell. |
| TLS verification | Remained enabled throughout. No TLS bypass was introduced. |

The direct probe did not validate the saved API key or model because it intentionally sent no credential.

## OpenAI request and model validation

The implementation uses `POST https://api.openai.com/v1/responses` with the configured model, a short `input`, `max_output_tokens`, `store: false`, and a server-side Bearer credential. This follows the current [OpenAI Responses API text generation guide](https://developers.openai.com/api/docs/guides/text). The API key is fetched by FastAPI from Laravel’s signed internal credential endpoint and is not part of the browser payload or ordinary Laravel-to-FastAPI assistant request.

After the local signed service configuration was repaired, one minimal live test ran through the actual Administrator controller → signed Laravel/FastAPI request → signed credential retrieval → OpenAI Responses API path. It succeeded for `gpt-5.4-mini`; the saved credential and model are accepted by the configured project. FastAPI was configured not to retry this explicit connection test, so this was one provider request.

## Security and authorization

- Administrator-only access remains enforced in the controller and route permission checks.
- The administrator credential is still stored using the model’s encrypted cast and exposed only as a masked suffix/configured flag.
- Failed test responses contain only an allowlisted category and a safe instruction. The saved key is preserved during testing.
- Test requests retain Laravel’s signed HMAC service authentication and FastAPI nonce validation. The code does not send the key to the frontend.
- The one live call used the approved signed architecture; it did not use an unsigned or direct credential-bypass path. No key or raw provider response was printed or logged by the test script.

## Verification results

| Check | Result |
|---|---|
| `php artisan test --filter=AiConfigurationTest` | **8 passed, 75 assertions.** Includes Administrator authorization, encrypted/masked key handling, saved-key preservation, missing-token diagnostics, and sanitized category handling. |
| `python-ai/.venv/Scripts/python.exe -m pytest python-ai/tests/test_api.py -q` | **33 passed**, one upstream Starlette/httpx TestClient deprecation warning. Covers provider categories, TLS/DNS/timeout transport classification, signed test behavior, and missing credential-retrieval configuration. |
| `npm run test:frontend` | **10 passed.** |
| `npm run build` | **Passed.** Vite reports the existing large-chunk advisory (540.98 kB minified JS); no build failure. |
| `vendor/bin/pint --test` | **Passed** after formatting the touched Laravel files. |
| Signed live Laravel ↔ FastAPI diagnostic | **Authenticated** after setting the shared local token. |
| Live OpenAI connection test | **Connected** for configured model `gpt-5.4-mini`; one minimal Responses API request. Successful timestamp was persisted in local SQLite and one sanitized usage/audit result was recorded. |
| Database | Configured local SQLite database contains the `ai_configurations` table and the encrypted administrator credential. Tests used the configured isolated test database. No new migration was required. |
| `git diff --check` | Not available because this project directory has no Git metadata. |

## Follow-up: recurring browser test failures

Subsequent clicks through the browser recorded `provider_unavailable` failures after about 5.5 seconds, even though an isolated CLI test could succeed. The local Laravel listener on port 8000 is PHP’s single-request development server. FastAPI’s connection-test request was waiting for Laravel while also calling back to that same occupied listener for the saved credential; its credential-fetch client times out after five seconds. This caused the repeated failures.

Kept the signed credential-retrieval design and configured the ignored local `.env` to use `http://127.0.0.1:8002/api/internal/ai-credential`. A second PHP listener now serves that callback on loopback independently of the main application listener. FastAPI was restarted to load the new URL. The callback route returns HTTP 401 to an unsigned request; health checks pass on ports 8000, 8001 and 8002. The same Administrator controller test path then returned HTTP 200 with `connected`, and the saved status was updated.

Added `scripts/start-ai-credential-endpoint.ps1` to start the separate local callback listener after a workstation restart. `.env.example` now documents the Windows PHP development-server requirement. If the callback worker is stopped, run that script and restart FastAPI. No production service was contacted or changed.

## Remaining setup and status

The local Administrator page should now show the successful test timestamp after refreshing status. Keep the generated service token in the ignored local `.env`; do not put it in `VITE_*` variables or commit `.env`. Production and staging still need their own secret-managed token and reachable credential URL configuration. **Local connection status: verified. Production status: not tested.**
