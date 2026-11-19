# Phase 7: Profit Impact Formulas

For one consistent currency:

```text
Authorized commercial revenue = accepted quotation total + approved ACR amounts
Projected final cost = max(original estimate total, verified actual cost to date)
Projected gross profit = authorized commercial revenue - projected final cost
Projected gross margin % = projected gross profit / authorized commercial revenue * 100
Incremental ACR profit impact = approved ACR amount - ACR actual cost
Unrecovered ACR cost = max(ACR actual cost - approved ACR amount, 0)
Forgone proposed revenue = max(ACR proposed amount - approved ACR amount, 0)
```

The current endpoint uses verified actual expense totals and original approved estimate totals as an interim projection; the estimate remainder is inferred as `max(estimate - actual, 0)` because no Phase 7 remaining-cost forecast is available. It excludes pending proposed charges from revenue and does not add company-absorbed cost a second time. Results are withheld when the quotation currency is unavailable. Currency conversion, tax normalization, forecast remaining-cost inputs, and full realized-profit reporting are not implemented.
