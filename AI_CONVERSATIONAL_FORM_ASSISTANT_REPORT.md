# IFC Conversational AI Form Assistant Report

## Features implemented

- Added a document attachment area to the IFC Assistant with multi-file selection, upload progress, size, scan and extraction status, errors, and removal.
- Added authenticated upload, list, and delete endpoints. Attachments are scoped to the signed-in user and a browser session key.
- Added a persistent Sales Inquiry assistance session that asks one required field at a time, tracks completion, supports resuming, and saves only after explicit confirmation.
- The assistant accepts an exact match to a client owned by the current Sales employee, plus LCL/FCL, mode, origin, destination, and cargo description. Saving calls the existing Sales inquiry controller so Laravel validation, draft status, ownership checks, and audit logging still apply.
- Added server-side extension, detected MIME, and signature validation; file count and size caps; randomized private storage; and the existing malware scanning and quarantine service.
- Clean files are explicitly marked as blocked because the document extraction service is unavailable. Quarantined files cannot be processed.

## Supported forms

Sales Inquiry (`sales_inquiry`) is the only enabled form. Its server-controlled required-field list reflects the existing Laravel validation: client, service type, transport mode, origin, destination, and cargo description. Optional and structured location fields are not collected by the assistant. No other modules are enabled.

## Supported document types

PDF, JPG, JPEG, PNG, DOCX, XLSX, and CSV. Each file is limited to 10 MB; each browser session can attach up to 10 files. DOCX and XLSX receive a ZIP signature check only; no document content is extracted.

## New routes

- `GET /api/assistant/documents?session_key={uuid}`
- `POST /api/assistant/documents` (multipart `documents[]` and `session_key`)
- `DELETE /api/assistant/documents/{document}`
- `GET /api/assistant/form-sessions`
- `POST /api/assistant/form-sessions`
- `POST /api/assistant/form-sessions/{session}/answer`
- `PUT /api/assistant/form-sessions/{session}/fields/{field}`
- `POST /api/assistant/form-sessions/{session}/draft`

They require the existing authenticated, active account and `assistant.use` middleware. Upload and delete use Laravel CSRF protection and the routes are throttled.

## Database changes

Added `assistant_documents` through migration `2026_11_09_090000_create_assistant_documents_table.php` and `assistant_form_sessions` through `2026_11_09_090100_create_assistant_form_sessions_table.php`. They record attachment ownership/security state and per-user conversation state respectively. The migrations were exercised in the in-memory test database only; neither has been applied to a persistent database.

## AI question engine behavior

The question sequence is deterministic and server-controlled: match an owned client, choose LCL/FCL, choose Sea/Air/Road/Rail, then provide origin, destination, and cargo description. If an answer explicitly contains multiple supported details, the engine records them and skips those questions. It does not infer missing values. Employees can edit the collected values during review. Sessions are scoped to the signed-in user, persist for resumption, and present a final review with progress before the employee confirms saving a draft. Asking to create a Sales Inquiry starts the flow.

## OCR extraction verification

Not available. The current FastAPI service has no document parsing/OCR route or parser dependencies. There is no OCR output, field extraction, source page reference, ID extraction, document preview, conflict detection, or document-processing AI usage record. Uploads are not sent to an external provider. ID uploads are accepted but remain blocked and unprocessed.

## Security controls

- The existing `DocumentScanService` scans uploads before writing them; infected, scanner-unavailable, and scan-error files stay in quarantine.
- Files use randomized paths on the private local disk. No attachment download route was created.
- Server-side extension/MIME/signature checks, 10 MB per-file limit, 10-file session cap, permission middleware, user and session scoping, and rate limiting are active.
- Other users' document IDs return 404. Document contents are not passed to the model, so no untrusted document text can act as instructions.
- Form sessions are owner-scoped and require both `assistant.use` and `inquiries.create`; client lookup is restricted to the employee's own Sales clients. Draft creation requires explicit confirmation and invokes current Laravel validation.
- Automatic form writes, approvals, invoice actions, and financial actions are not available.
- Retention cleanup, extraction audit events, and prompt-injection evaluations are not implemented.

## Test results

- Laravel attachment feature tests: **2 passed, 10 assertions**.
- Full Laravel suite after the initial conversation session was added: **143 passed, 5 skipped, 1,482 assertions** (620.66 seconds).
- Final conversational session tests after multi-answer capture and review editing: **3 passed, 26 assertions**.
- Python FastAPI tests: **33 passed**, one existing Starlette/httpx deprecation warning.
- Frontend tests: **11 passed**.
- Vite production build: **passed**, with a large-chunk advisory.

## Remaining limitations

The document and ID extraction part of acceptance remains unmet. The assistant can now collect the six required Sales Inquiry fields, review and edit them, and create a confirmed draft through the existing validated route. It does not read documents, handle client creation, collect optional/conditional fields, prefill the regular form UI, or implement other department forms. Completing document-based filling requires the parser/OCR pipeline, source-aware extraction, and prompt-injection testing.

## Final implementation status

**Partial and not acceptance-complete.** A bounded Sales Inquiry conversation and confirmed draft save are implemented, along with secure attachment intake. Document extraction remains disabled because its processing infrastructure is not present; no uploaded document contents are processed.
