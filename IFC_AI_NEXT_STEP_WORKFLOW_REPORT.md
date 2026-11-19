# IFC AI Next-Step Workflow Report

## Root cause

After the assistant saved an inquiry draft, it returned a success message and refreshed records, but it did not evaluate the persisted inquiry state and employee permissions to offer a real next action. The frontend had no guided review/submit interaction, and directly submitting from a post-save button would have skipped a review step.

## Workflow engine

`InquiryNextStepRecommendationEngine` builds response text and action descriptors from the saved `ClientInquiry` state and the authenticated user's effective permissions. The draft-save endpoint reloads the committed, owner-scoped inquiry and attaches the recommendation to its action result. It does not rely on generated AI text to decide status or permissions.

The assistant now explains that a draft has not been submitted and offers authorized actions. The response template supports English, Taglish, and Filipino guidance; language detection uses the linked conversation's recent user messages. After submission, the engine re-reads the persisted record and recommends the next state-specific actions.

## Contextual actions

- **Review and Submit** loads the latest owner-scoped inquiry from Laravel and presents its current client, service, mode, route, cargo, and status inside the assistant. Missing required client, mode, route, or cargo details are identified and block the submit action.
- **Confirm and Submit for Review** is a separate explicit action. Its endpoint requires `confirmed: true`, checks assistant and submit permissions, verifies ownership, delegates to the existing Sales submission operation, and preserves its audit log. It never approves the inquiry.
- **Edit Details** opens the existing inquiry editor for the saved draft, after checking the current table record and update permission.
- **Create Another Inquiry** starts the existing assistant workflow in a new conversation.
- **View Inquiry** and **Check Status** use the existing owner-scoped inquiry data. The check action refreshes guidance from the database.
- **Create Shipment Forecast** appears for an under-review inquiry only when the current account can view and create forecasts and view the inquiry. The existing forecast operation controls the actual transition.
- **Prepare Cost Estimate** appears for a forecasted inquiry only when an active forecast exists and the account can view the inquiry and view/create cost estimates.

Actions are rendered from the server response, not from hard-coded frontend permissions. Unsupported statuses receive no invented transition.

### Routes

- `POST /api/assistant/form-sessions/{session}/draft` returns the committed draft and its next-step recommendation.
- `GET /api/assistant/workflows/inquiries/{inquiry}` reloads owner-scoped details and current permitted actions.
- `POST /api/assistant/workflows/inquiries/{inquiry}/submit` requires explicit confirmation and runs the existing audited submission operation.
- Existing inquiry, forecast, and commercial APIs continue to perform all reads and writes.

## Supported inquiry statuses

IFC currently uses `draft`, `submitted`, `under_review`, `forecasted`, `withdrawn`, and `cancelled`. The engine explains submitted inquiries as awaiting review, under-review inquiries as pending a decision, and `forecasted` as planning rather than final approval or an operational shipment. It offers forecast or estimate actions only where the existing workflow and permissions allow them.

The codebase does not implement `returned` or `rejected` inquiry states. If such a value is encountered, the assistant can show the saved record and status but does not claim a correction or resubmission path.

## Automatic UI synchronization

Successful guided submission returns a `sales_inquiry.updated` action result containing actual ID, reference, status, correlation ID, and next steps. The existing same-session invalidation store refreshes inquiry tables and dashboard statistics from Laravel without inserting optimistic rows or reloading the browser. Filters and pagination remain in component state. The assistant conversation remains open and its messages are stored with the conversation record.

Forecast and cost-estimate actions launch the existing page with the inquiry selected and open the real creation form. No broadcast infrastructure or cross-user event was added.

## Security verification

- Guidance and detail reads enforce `inquiries.view` and owner scope.
- The guided submit route enforces `assistant.use` and `inquiries.submit`; the existing Sales operation rechecks submit permission, ownership, and `draft` status and writes the audit record.
- The existing Sales operation revalidates required inquiry fields before changing the status.
- The guided endpoint rejects missing explicit confirmation and a second submit cannot transition an already-submitted inquiry.
- Cross-user inquiry IDs return 404; unauthorized users receive 403.
- No approval, quotation issuance, payment, cash release, or shipment action is performed by the assistant.

## Tests and checks

Tests cover server-generated draft actions, missing confirmation, successful submission and status response, owner isolation, submit permission enforcement, audit logging, Taglish/Filipino copy, unknown statuses, missing required fields, verified forecast/cost-estimate next actions, and existing sales inquiry/forecast workflows. The frontend test suite covers the existing action invalidation and cross-tab behavior.

Final verification:

- Laravel focused regression: **20 passed, 265 assertions** across the final regression run plus the separately rerun forecasted/under-review guidance test (`AssistantFormSessionTest`, `AssistantWorkflowTest`, and `SalesForecastWorkflowTest`).
- Frontend: **16 passed** (`npm run test:frontend`).
- Python: **33 passed**, with one upstream Starlette/httpx deprecation warning.
- PHP Pint: passed for changed PHP files.
- Vite production build: passed. Vite reported the JavaScript bundle size is above its 500 kB advisory threshold.
- Browser end-to-end tests were not run.

## Remaining limitations

- No returned/rejected states exist in the current inquiry workflow, so no feedback/correction buttons are shown for them.
- Conversational editing opens the existing Sales Inquiry editor with the draft selected. Review and submission can be completed inside the assistant.
- Submission and review status remain tied to IFC's existing role permissions and status transitions; no fixed approver role is claimed because the workflow does not encode one mandatory reviewer.
- Other modules are not given speculative next-step actions. Their guidance can be added when their actual operations and state transitions are integrated with the recommendation engine.
