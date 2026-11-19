# IFC Sales AI Tutorial Implementation

**Date:** 2026-10-24
**Scope:** Permission-aware IFC Assistant tutorials for Sales workflows, with verified inquiry-state and shipment-capacity context.
**Data safety:** No production data or financial policies were changed.

## What changed

- Added a versioned Sales tutorial catalog covering the Sales dashboard, clients, inquiries, inquiry review, forecasting, cost estimates, quotations, calendar, availability, and status monitoring. Each guide describes prerequisites, permissions, navigation, steps, expected results, approval dependencies, and troubleshooting.
- Added an Administrator guide for viewing Roles & Permissions. The guide list and related navigation links are filtered using the signed-in account's actual permissions.
- Reworked the IFC Assistant panel with Ask IFC and Tutorials tabs, current-page context, owned-inquiry selection, suggested questions, step-by-step tutorial progress, restart/previous/next controls, and links to authorized pages. Tutorial progress is stored per user in browser local storage.
- Added a Laravel tutorial API and routed Sales-context prompts through a dedicated service. The service verifies the requested page authorization and inquiry ownership. It sends only a module/page key and persisted inquiry status to FastAPI; it does not send inquiry IDs, references, client details, or cargo text as AI context.
- Extended the existing FastAPI classifier with Sales tutorial intent and English/Taglish language classification. It remains a deterministic classifier; it does not generate unsupported operational or financial facts.
- Added deterministic answers for persisted inquiry status and verified capacity. When FastAPI is unavailable, the Sales workflow guide can still answer from the tutorial catalog and saved Laravel state.

## Workflow facts represented accurately

- An inquiry in `submitted` is not described as finally approved. `inquiries.review` moves an owned inquiry to `under_review`; the separate Sales Manager `sales.inquiries.approve` queue action is labeled “Approve for review” and makes that same transition.
- The current inquiry workflow does not encode one mandatory approver or returned/declined states. Tutorials do not claim those states or actions exist.
- Forecasts are planning records, not operational shipments. A forecast is never counted as a shipment or as capacity.
- Availability responses use currently valid, verified capacity-register entries. An empty result says availability is unconfirmed and recommends confirming with the recorded carrier/forwarder.
- Inquiry-specific answers use the inquiry's saved database status. A user message cannot override that status. The lookup is restricted to inquiries owned by the signed-in Sales user.

## Authorization and privacy

The existing `assistant.use` authorization remains enforced by the Laravel route middleware. Tutorial lists omit guides whose page permissions are missing. A supplied Sales page that the account cannot access is rejected. Explicit inquiry context requires `inquiries.view` and an owner match; another user's inquiry returns not found. FastAPI receives minimal classification context only, while Laravel retains authority over record state and permissions.

## Changed files

- `resources/ai-tutorials/v1/sales.php`
- `app/Services/TutorialKnowledgeBase.php`
- `app/Services/SalesTutorialAssistant.php`
- `app/Services/AiAssistantClient.php`
- `app/Http/Requests/AssistantMessageRequest.php`
- `app/Http/Controllers/Api/DashboardController.php`
- `routes/api.php`
- `resources/js/App.vue`
- `resources/js/style.css`
- `python-ai/app/main.py`
- `python-ai/tests/test_api.py`
- `tests/Feature/AssistantTutorialTest.php`
- `tests/Browser/ifc-workflow.spec.js`

## Verification

| Check | Result |
|---|---|
| Python suite (`python-ai/.venv/Scripts/python.exe -m pytest python-ai/tests -q`) | 20 passed; one upstream Starlette/httpx TestClient deprecation warning. |
| Frontend tests (`npm run test:frontend`) | 9 passed. |
| Vite build (`npm run build`) | Passed; 32 modules transformed. Vite reports the generated JavaScript chunk is about 529 kB, above its 500 kB advisory threshold. |
| Laravel Pint (`vendor/bin/pint --test`) | Passed. |
| Existing Assistant API tests (`php artisan test --filter=AssistantApiTest`) | 7 passed, 1 skipped (optional live Python integration requires `AI_E2E_URL` and `AI_E2E_TOKEN`). |
| Tutorial feature tests (`php artisan test --filter=AssistantTutorialTest`) | 6 passed; 20 assertions. |
| Browser E2E (`npm run test:e2e -- --grep Tutorial`) | 1 Chromium test passed. It verified page-aware tutorial selection, tutorial list entry, step navigation, restart, and related navigation. Playwright emitted a harmless `NO_COLOR`/`FORCE_COLOR` environment warning. |

## Limits and follow-up

- FastAPI intent/language detection is rule-based and the tutorial text is curated application content. This is not a generative AI agent or a financial reporting system.
- Only Sales tutorials and the Administrator Roles & Permissions guide are currently authored. Other departments need their own verified workflow catalogs before they can receive equivalent contextual help.
- The browser test targets tutorial visibility, progress controls, and registered navigation. It does not represent department UAT or test every application workflow.
- Service health and signed live Laravel-to-FastAPI communication depend on local AI service configuration. No production AI credentials or external model service were used.

## Acceptance summary

The Assistant now provides permission-filtered, page-aware Sales tutorials and can answer supported inquiry-status and capacity questions from authorized, saved IFC data. It preserves Laravel authorization and does not invent approval states, operational shipments, capacity, or financial figures. Production deployment and business UAT were not performed.
