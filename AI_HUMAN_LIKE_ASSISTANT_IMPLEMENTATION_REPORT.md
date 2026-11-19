# Human Like IFC Assistant Implementation Report

## Status

**Implemented with bounded scope; document extraction acceptance remains incomplete.** The assistant now supports live, context aware OpenAI responses and private conversational sessions. It can guide a user through a Sales Inquiry draft. Uploaded documents remain securely stored but are not read or used to fill forms because the application has no document parsing or OCR service.

## 1. Root cause of robotic responses

The assistant combined deterministic keyword intent matching, static Sales tutorials, and local fallback answers with OpenAI responses. When the AI service failed, the Sales assistant silently substituted local text, while the sidebar displayed responses as plain text and did not identify static guidance. The result could feel like a FAQ and expose Markdown syntax, route strings, or permission names.

## 2. Conversational improvements

- The FastAPI OpenAI Responses prompt now asks for warm, concise coworker style answers in English, Filipino, or Taglish, one follow up question at a time, and acknowledgement of corrections.
- Recent conversation history is passed separately from trusted application context. The model receives at most eight prior turns, each capped at 1,200 characters. History is labeled untrusted and cannot override verified context.
- The assistant is instructed not to claim a record was saved unless the verified IFC context confirms it, and not to perform approvals, submissions, or record mutations from chat text.
- Static Sales status, capacity, and tutorial answers remain available where they are backed by authorized IFC data. The UI marks degraded answers as static guidance and gives an explicit live service unavailable message.

## 3. OpenAI integration

The existing Laravel to FastAPI path remains the live assistant path. FastAPI calls the configured OpenAI Responses API model and parses provider token usage. The existing administrator enable/disable controls, encrypted credential handling, signed internal credential channel, TLS requirements, Laravel service authentication, timeouts, usage event recording, and safe provider error classification remain in place. This implementation did not change credentials.

Sales context sent to the model now uses friendly authorized action labels and page labels rather than raw permission keys or route paths. Server side page authorization remains authoritative. Non Sales assistant requests continue to use the configured OpenAI model.

## 4. Conversation memory

- Added `assistant_conversations` with a UUID, user ownership, current module/page, JSON message history, activity timestamp, and 30 day expiry.
- Added list, create, resume, and delete endpoints under the authenticated `assistant.use` permission and throttling.
- Chat requests accept a conversation UUID. Laravel looks up the active session by both UUID and signed in user, forwards recent history to the AI service, and stores only the latest 20 user/assistant turns.
- The UI remembers the active UUID per user in `sessionStorage`, displays recent conversations, and provides New Conversation, Resume, and Delete controls.
- Expired conversations are inaccessible. An hourly Laravel scheduled command physically deletes expired rows; the host scheduler must run Laravel's scheduler for periodic cleanup.

## 5. Document reading

The existing attachment intake remains available: authenticated owner/session scoped uploads, a 10 MB per file limit, a 10 file session limit, supported type checks, malware scanning/quarantine, randomized private storage, and removal. The UI shows scan and extraction status.

**Extraction is not enabled.** The FastAPI service has no PDF/image/office parser or OCR endpoint, and uploaded contents are not sent to OpenAI. The system does not extract ID data, report source pages, identify document conflicts, detect missing fields from a document, or prefill a form from an attachment. It does not collect or store extracted sensitive ID data.

## 6. Form assistance

The server controlled Sales Inquiry session guides an authorized Sales user through the required client, service type, transport mode, origin, destination, and cargo fields. It can capture multiple explicit values in one answer, validates an owned client, tracks completion, allows editing during review, and saves only after explicit confirmation. Draft creation goes through the existing Laravel inquiry workflow and its validation/audit behavior. The assistant does not submit or approve the inquiry.

The session currently supports only Sales Inquiry. It does not create clients, read documents into form fields, prefill the ordinary Sales form UI, or prepare forms for other modules.

## 7. Interface

The assistant sidebar now shows natural chat bubbles, accessible message status, a typing indicator, readable Markdown paragraphs and lists, authorized navigation links, a live/static guidance indicator, conversation history, and a New Conversation option. HTML is escaped before formatting. Markdown links are only made clickable when their exact destination exists in the signed in user's rendered navigation; unknown route strings are replaced with a friendly label. Raw permission identifiers are replaced with plain language. Frontend tests exercise formatting, HTML escaping, denied links, allowed links, and permission key filtering.

The existing attachment panel and Sales Inquiry progress/review/confirmation controls remain integrated. Streaming responses and document previews are not supported by the current services.

## 8. Security and cost controls

- Conversation reads, writes, and deletes are scoped to the authenticated owner; unknown or other-user UUIDs return 404.
- Conversation history is bounded and expires after 30 days. Users can delete their own conversations.
- AI receives no document contents, arbitrary database instructions, raw permission identifiers, or internal signing credentials.
- Sales action descriptions are permission filtered; the model cannot execute actions. Actual draft persistence still requires server validation and explicit user confirmation.
- Existing AI usage and cost monitoring records successful provider token usage and failures through Laravel. PHP estimates and original USD/exchange-rate details remain within the existing monitoring implementation.
- No approvals, quotation controls, invoice issuance, financial close, or privileged database actions were added to the assistant.

## 9. Verification

Latest focused verification after the conversation changes:

- Laravel assistant API and form session tests: **13 passed, 1 skipped** (the skipped case requires a running local FastAPI integration service).
- Python FastAPI suite: **33 passed**. The OpenAI request test uses a mock transport and now checks language instructions, bounded history, and separation of conversation history from trusted context. No real OpenAI call was made.
- Frontend tests: **13 passed**, including safe Markdown rendering checks.
- Vite production build: **passed**; Vite reports the existing built JavaScript bundle is above its 500 kB advisory threshold.
- Pint formatting: run on changed Laravel PHP files.

## 10. Remaining limitations

Document and ID extraction acceptance is unmet until a parser/OCR pipeline is added with source-aware output, user review, sensitivity controls, and extraction tests. The implemented form is limited to Sales Inquiry drafts. The assistant does not stream responses, preview attachments, create clients, or support other departmental forms. Live OpenAI behavior depends on administrator configuration, valid credentials, network access, and quota; when unavailable, the UI identifies static guidance and the Tutorials tab remains available.
