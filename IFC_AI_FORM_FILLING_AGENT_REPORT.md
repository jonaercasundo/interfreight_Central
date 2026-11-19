# IFC AI Form-Filling Agent Report

## Root cause

The client-creation intent resolver treated requests such as “Help me fill up the form” as workflow guidance. The conversational form workflow was implemented for Sales Inquiries only, and its session lookup could also reuse a conversation-level session without distinguishing form type. The Laravel Client Management CRUD already had the authoritative schema and validation, but the assistant did not route into it.

## Implementation

- Routed natural English/Filipino/Taglish client-fill requests on Client Management into a dedicated client form workflow instead of tutorial guidance. AI-classified `create_client` actions are also routed to the same server-owned workflow.
- Added persistent client draft sessions, field-by-field questions, real backend choices (LCL, FCL, Mixed), correction support, review and explicit confirmation before save.
- Connected assistant answers to the actual Vue Client Management form. AI-populated values are marked for review, while manual edits are preserved.
- Save runs through the existing Laravel Client creation operation, permission checks, transaction/audit behavior, and a locked/idempotent session. On success the assistant shows the returned client reference and offers the next authorized Sales Inquiry step; the table refreshes through the existing business-action sync.
- Separated client and inquiry session lookups and added a migration that allows multiple form sessions per conversation.
- Added optional GPT-6.1 Sol, GPT-6 Astra, and GPT-6 Luna choices to Administrator → AI Configuration. The existing GPT-5.4 mini choice remains the fallback. Existing credential handling is unchanged.

## Verification

- Laravel targeted feature suites: `AssistantActionWorkflowTest`, `AssistantFormSessionTest`, `AssistantWorkflowTest`, and `AiConfigurationTest` — 27 passed (284 assertions).
- Python: `.venv\\Scripts\\python.exe -m pytest tests` — 33 passed.
- Vue/frontend: `npm run test:frontend` — 16 passed.
- Production assets: `npm run build` — passed. Vite reports the existing main JS bundle is above 500 kB (610.14 kB).
- Browser E2E full spec initially passed 11 of 13 tests. The tutorial selector was updated, and the client workflow test was corrected for the review-card locator and conversation initialization. A targeted rerun still failed: the browser remained in “thinking” and the `/api/assistant` POST ended without an HTTP response in the isolated Playwright server. The same request passes the Laravel feature test; the browser-server discrepancy remains unresolved.

## Model compatibility and usage

GPT-6 IDs are selectable, but successful use depends on the configured OpenAI project having access and quota. The existing connection test is the way to verify account access. No credentials were changed. Token usage continues to be recorded by the existing usage service; estimated cost is available only when an effective model price has been entered and verified in the existing price table. Philippine Peso estimates therefore depend on configured exchange/pricing data.

## Remaining limitations

- The current Client model and CRUD form represent organizations (`company_name`) and logistics service type (`client_type`: LCL/FCL/Mixed). There is no individual-versus-company field or individual client schema. The assistant follows the real application schema and does not invent one; supporting individual clients requires a product/schema change.
- Document storage/scanning exists, but configured document extraction/OCR is not available in this environment. This implementation does not claim ID/registration document extraction. Uploaded documents cannot currently produce verified form suggestions.
- Form actions are server-owned and validated by Laravel; the Python intent classifier continues to return structured intent JSON rather than executing OpenAI function/tool calls. Model selection alone does not add tool execution.
- No live OpenAI API call was made as part of these regression checks.




