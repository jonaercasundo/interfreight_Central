# Phase 17 IFC Department UAT Preparation

This plan contains synthetic scenarios only. It is a checklist for authorized IFC users to execute in an isolated staging environment; no user acceptance has been recorded.

## Synthetic demonstration dataset

Use the prefix `IFC17-UAT` on every record and use the `PHP` currency only as a test denomination. These values are invented test inputs, not approved revenue, tax, FX, pricing, or accounting policy.

| Record | Synthetic value | Expected relationship / state |
|---|---|---|
| Client | Northstar Demo Logistics | One synthetic client owned by the UAT Sales user. |
| Inquiry | `IFC17-UAT-INQ-001` | Manila to Cebu; LCL; target shipment month set to the next staging month. |
| Forecast | `IFC17-UAT-FC-001` | Linked to inquiry; test-only volume 2 shipments. |
| Cost estimate | `IFC17-UAT-CE-001` | Shipping line 18,000; operations 4,000; transport 5,000; forwarding 2,000; contingency 1,000; total estimate 30,000 PHP. |
| Quotation | `IFC17-UAT-QT-001` | Client-facing test amount 36,000 PHP; proceed only through the configured internal approval and client acceptance workflow. |
| Budget | `IFC17-UAT-BD-001` | Monthly test allocation 40,000 PHP; approve/allocate only with the role and authority already configured in staging. |
| Shipment | `IFC17-UAT-SHP-001` | Create from the accepted quotation; record the single canonical shipment identifier for every department. |
| Successful change | `IFC17-UAT-CHG-APPROVE` | Test-only additional charge 1,500 PHP with supporting synthetic evidence; route through client approval and authorization. |
| Rejected change | `IFC17-UAT-CHG-REJECT` | Test-only additional charge request 900 PHP; reject with reason and verify original cost remains recorded as company-absorbed and excluded from billable sources. |
| Collection | `IFC17-UAT-PAY-001` | Use only a synthetic receipt against a test invoice fixture; do not call an official statutory issuance route. |

Create the same shipment once and reuse its canonical identifier in Operations, Transport, Forwarding, expenses, billing eligibility, collection fixtures, and milestone review. Do not create a second shipment to represent another department.

## Department scenarios

For each row, the tester must record actual outcome and evidence. “Pass” is not preselected; a blocked action caused by missing policy/configuration must be recorded as Blocked with the response and evidence.

| Department / role | Scenario and expected outcome | Evidence to capture |
|---|---|---|
| Sales | Create client, inquiry, forecast, cost estimate and quotation. Submit for review, record acceptance, and confirm the accepted quotation baseline cannot be edited in place. | Client/inquiry/forecast/quotation IDs, approval history, response status, screenshots, validation messages. |
| Operations | Open the shared shipment, attach synthetic documents, complete operations checklist/customs data and its own milestone. An unscanned document must remain quarantined. | Canonical shipment ID, assignment, milestone event, document scan status, inaccessible quarantine proof. |
| Transport | Use the same shipment; assign a synthetic trip, update dispatch/delivery and attach proof of delivery. Verify the Transport milestone changes independently. | Shipment/trip IDs, event times, delivery/POD state, screenshot. |
| Forwarding | Use the same shipment; record synthetic booking/container/route tracking as applicable and complete its independent milestone. | Shipment/booking/container IDs, milestone state, validation results. |
| Billing | Review eligible sources, verify approved change is eligible and pending/rejected change is excluded. Confirm official issue remains blocked unless Finance/Legal have formally approved and enabled statutory configuration. | Invoice draft/source IDs, rejected-source exclusion, HTTP/UI block evidence, no official invoice assertion unless separately authorized. |
| Accounting | Verify estimates, approved budget/allocation, actual expense receipt checks, billable-source reconciliation and collection record linkage. Do not treat cash advances as expenses. | Budget and expense IDs, reconciliation values, receipt status, collection allocation evidence. |
| Finance | Review policy status, approve/reject only using an authorized Finance test account and documented approval reference; verify maker-checker and event history. Confirm unknown revenue/accrual/FX keeps profitability null and closing blocked. | Policy version/event IDs, actors, before/after state, close blockers, null profitability response. |
| Executive | Review cross-department status, exception visibility and currency-separated metrics. Confirm unknown financial facts are shown as unavailable rather than inferred. | Dashboard/report date range, source record IDs, screenshot, inaccessible action checks. |

## Cross-department invariants

- All departments refer to the same canonical shipment record and shipment number.
- Operations, Transport, Forwarding, Billing, Collections and Accounting milestones are independently recorded; one department cannot imply another has completed work.
- The approved additional charge can become billable only after its required evidence and client authorization are recorded.
- The rejected charge remains in actual cost history as company-absorbed and is not included in invoice sources.
- Collection is not recognized revenue. An issued invoice is not a substitute for cash collection or a Finance-approved recognition event.
- Missing recognition, final accrual, FX, statutory settings, scanner availability or authorization must fail closed and produce a reviewable blocker.

## Evidence and sign-off record

| Field | Entry |
|---|---|
| UAT run ID / date | |
| Staging build / source revision | |
| Tester name / department / role | |
| Scenario ID | |
| Result: Not Started / Pass / Fail / Blocked | |
| Expected outcome | |
| Actual outcome | |
| Shipment / record IDs | |
| Screenshot / audit event / response reference | |
| Defect ID and severity | |
| Tester notes | |
| Department owner sign-off and date | |

## Approval boundaries

This plan does not approve revenue recognition, expense accrual, FX, statutory tax, legal entity data, invoice numbering, invoice correction, production security controls, or a production release. Finance and Legal approvals must be independently recorded before any gated feature is enabled. Department sign-off must be completed by actual authorized users.
