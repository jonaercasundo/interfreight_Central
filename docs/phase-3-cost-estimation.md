# Phase 3 Cost Estimation

## Scope

Cost estimates are internal forecasts linked to a Phase 2 client inquiry and optionally to an active shipment forecast. They do not create a payment, cash disbursement, journal entry, invoice, or completed shipment.

## Estimate data

`cost_estimates` stores the estimate header, owner, inquiry/forecast references, version, state, currency, assumptions, review evidence, totals, and optimistic lock version. `cost_estimate_lines` stores cost categories, supplier, quantity, unit, source currency, exchange rate, taxes, payer, recoverability and funding requirement. Selling prices belong to `quotation_lines` and remain separate.

References use `EST-YYYY-000001`. `DocumentReferenceGenerator` increments the yearly sequence inside the estimate creation transaction. Supported categories are declared in `CommercialCalculator::COST_CATEGORIES`.

## Workflow

Sales creates and edits drafts, submits them for Accounting review, and can duplicate a reviewed estimate into a new version. Accounting may approve, reject, or request changes. Submitted and approved estimates cannot be edited in place. Only an approved estimate associated with a current active forecast may seed the initial quotation.

## Currency and funding

The estimate currency is the reporting currency for the estimate. Foreign-currency lines require a positive `exchange_rate_to_estimate`; source and date are retained when supplied. Funding requirement is summed from explicitly flagged lines and is tracked separately from expense totals. Cash-requirement views aggregate submitted and approved estimates by planned month/currency.

## Access

Laravel permissions guard every route and action. Sales sees estimates for inquiries they own. Accounting, Finance, Executive, and Administrator have broader read scopes only where their permissions and roles allow it. Cost and margin information is omitted from view responses when the viewer lacks the corresponding access.
