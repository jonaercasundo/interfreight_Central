# IFC Action-Oriented AI Assistant Report

## Root cause

The assistant routed requests through its tutorial/help response path. Natural action phrasing was not consistently distinguished from informational questions, and the response path had no durable action workflow to resume. The conversational UI therefore produced instructions instead of starting the Sales Inquiry form.

## Intent routing

- Added a Laravel intent resolver for common English and Taglish inquiry-creation phrasing, with informational-question detection.
- Known local action phrases start the workflow without an OpenAI round trip. Other requests may use the configured AI service, whose structured intent/action proposal is validated by Laravel before routing.
- The action registry checks `inquiries.create`, resumes one session per user/conversation, and returns the server-authored next question. An AI proposal cannot execute a business action by itself.
- OpenAI unavailable behavior remains explicit through the existing service fallback; local workflow answers do not depend on OpenAI.

## Workflow state and Sales Inquiry integration

The additive durable form-session schema links an assistant session to its conversation. The workflow currently persists `collecting`, `review`, `saved`, and `cancelled` states, collected fields, step position, and resulting inquiry ID. When older sessions contain only origin/destination countries, they are upgraded on resume and returned to location collection instead of being allowed to save incomplete route details. Review editors provide country and directory-backed location choices. Refresh/resume is conversation-linked. Repeated draft confirmation returns the existing draft.

The assistant collects existing/new client, service type, transport mode, country, catalog location, and cargo. Existing clients are searched only within the current sales owner's records; ambiguous results are presented as numbered choices in stable order. New client creation is held until confirmation. Countries and locations are checked against the configured freight directory and valid transport mode; users may explicitly mark a custom location. Review edits are validated and keep the structured location fields consistent. The final inquiry is created through the existing `SalesWorkspaceController` business validation inside a transaction. It is saved only as a draft after explicit confirmation; no submission or approval is triggered.

## Document handling

Assistant documents continue to use file type/signature checks, private storage, malware scanning/quarantine, per-user ownership, and existing size/count limits. An extraction processor is not configured in this application, so the assistant reports extraction as unavailable and does not claim it read an ID or populated fields. OCR/ID extraction and verification remain future work.

## Security and cost tracking

- Permission checks gate inquiry creation and new-client creation; client searches and session/document reads are owner-scoped.
- Workflow values and location IDs are validated server-side. The model cannot execute SQL, arbitrary requests, or privileged commands.
- Confirmation plus transaction/locking and conversation uniqueness protect business writes from accidental or duplicate execution. The idempotent draft endpoint allows up to 30 requests per minute, and the UI explains 429 retry timing without clearing the review state.
- No OpenAI credentials were changed. Existing AI usage recording and PHP cost summaries remain available for actual AI calls; deterministic local workflow transitions do not call or count as paid AI requests.

## Automated verification

- Python FastAPI: `python-ai/.venv/Scripts/python.exe -m pytest -q` — 33 passed, 1 Starlette/httpx deprecation warning.
- Frontend: `npm run build` succeeded; `npm run test:frontend` — 13 passed. Vite emitted its existing large-chunk advisory.
- Laravel: `php artisan test` — 152 passed, 5 optional integration tests skipped (1,577 assertions). The focused action/session suite after final fixes — 10 passed (120 assertions), including legacy-session recovery.
- PHP formatting: Laravel Pint applied to the changed PHP implementation files.

## Known limitations

- Document OCR/ID extraction is unavailable because no document processing service is configured.
- The supported conversational action is Sales Inquiry creation. Other classified intents (client creation outside an inquiry, editing existing inquiries, filling arbitrary forms, and record-status lookup) are not executable workflows yet and must not be represented as completed actions.
- The location directory is the application's configured reference set; it must be maintained to support additional countries, ports, airports, or approved custom locations.
- Workflow expiry is not yet enforced as a distinct persisted `expired` state; saved and cancelled workflows remain terminal.



