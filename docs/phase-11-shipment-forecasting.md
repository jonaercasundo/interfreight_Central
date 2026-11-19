# Phase 11 Shipment Forecasting

## Implemented baseline

The service accepts 1–72 contiguous monthly observations, a 1–6 month horizon, and nonnegative distinct master-shipment counts. The horizon requires at least 6, 9, 12, 16, 20, or 24 observations respectively. Missing months must be supplied with zero values. Unsorted, duplicate, non-contiguous, oversized, and invalid histories are rejected.

`moving-average-3.0.1` forecasts each requested month using the mean of the latest three completed months, rounded to a nonnegative whole shipment. It returns the input range, training cutoff, horizon, model version, point forecasts, and limitation text. It does not return confidence percentages or intervals.

Evaluation uses rolling-origin, time-ordered, one-step holdouts over up to the latest six available observations. It reports MAE, WAPE, bias, and the corresponding last-observation baseline metrics. The evaluation is descriptive and does not claim the moving average is superior. Input is historical actual shipments by planned departure; this forecast target is distinct from Sales inquiry forecasts.

## Limits

No real deployment history has been evaluated in this change. The model does not yet segment by client, service, direction, cargo, or department and does not forecast cash. Accuracy must be assessed against IFC production history in an approved non-production evaluation before the output is treated as a planning signal.
