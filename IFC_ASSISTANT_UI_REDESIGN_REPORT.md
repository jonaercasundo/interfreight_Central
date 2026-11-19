# IFC Assistant UI Redesign Report

## Summary

Redesigned the assistant as a conversation first panel modeled on the supplied reference: a restrained IFC header, spacious scrollable message history, and a fixed, roomy composer. Uploads, workflow tools, history, and tutorials now open from compact icon menus instead of occupying the default chat view.

## Components modified

- `resources/js/App.vue` — assistant structure, conversation actions, contextual menus, attachment queue, and compact Sales Inquiry flow.
- `resources/js/style.css` — responsive panel layout, message styling, fixed composer, menus, focus states, and reduced-motion behavior.
- `resources/js/assistantMarkdown.js` — safe Markdown rendering and permission-filtered route links used in chat messages.
- `app/Http/Controllers/Api/AssistantConversationController.php`, `app/Models/AssistantConversation.php`, `app/Http/Controllers/Api/DashboardController.php` — owner-scoped conversation titles and automatic first-message titles.
- `routes/api.php`, `database/migrations/2026_11_10_090000_add_title_to_assistant_conversations_table.php` — conversation rename endpoint and additive title column.
- `tests/Feature/AssistantApiTest.php`, `tests/Frontend/sidebarNavigation.test.js` — owner isolation, rename, safe rendering, and existing navigation coverage.

## UI changes

- Header matches the reference’s compact title and subtitle, with history, tutorials, new conversation, and close icon controls.
- The conversation area is the only panel region that scrolls. Messages show user and assistant turns, compact attachment chips, upload progress, and typing/error states.
- The bottom composer stays visible and includes a multiline input, workflow-tools button, paperclip menu, and send button. On mobile the assistant fills the screen and the composer respects the safe area.
- History is a compact overlay with resume, rename, new conversation, and confirmed delete actions.
- Tutorials open from a header menu and publish the selected steps into the conversation.
- Inquiry context, Sales Inquiry start/resume, and document attachment controls appear only in contextual menus. The form progress and review panel appear only during an active Sales Inquiry session.
- Unsupported document reading is disclosed after upload; the UI does not imply that file contents were extracted.

## Features preserved

OpenAI requests, Laravel-to-FastAPI authentication, user-scoped conversation storage and retention, permission-aware tutorials, private document uploads and malware scanning, Sales Inquiry validation/review/confirmation, and AI usage tracking remain on the existing backend paths. Conversation rename is owner-scoped. The new title migration is additive and was applied to the local SQLite database.

## Visual verification

The layout and spacing were checked against the supplied screenshot, including the slim header, open conversation canvas, subdued user bubble, rounded composer, and icon placement. Responsive CSS covers full-screen mobile layout, keyboard focus, reduced motion, and safe-area padding. The CUA browser inventory exposed no available browser surface, so a live screenshot could not be captured in this session.

## Tests

- Laravel assistant and form-session tests: **14 passed, 1 skipped**. The skipped test requires a separately running local FastAPI service.
- Frontend tests: **13 passed**, including Markdown escaping and allowed/blocked navigation links.
- Vite production build: **passed**. It reports the app JavaScript bundle above Vite’s 500 kB advisory threshold.
- Pint: **passed** for the changed PHP files.

## Remaining limitations

- The document processor/OCR path does not exist yet. Files can be uploaded securely and displayed in chat, but contents cannot be extracted or used to prefill fields.
- Existing attachment references are scoped to the browser document session rather than linked to a conversation record.
- Rename uses a native prompt dialog; an inline rename editor could provide a more integrated interaction.
- Browser-based visual and end-to-end checks were unavailable because this session had no browser surface.
