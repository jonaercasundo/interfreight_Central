# Phase 11 Python AI Architecture

Laravel remains the authority for identity, authorization, source data and persistence. `ExecutiveAnalyticsController` checks the Executive role and granular forecast-generation permission, retrieves monthly shipment aggregates from Laravel, calls the configured FastAPI service, validates the response shape, and persists a separate AI run. Python does not connect to MySQL.

`POST /api/v1/ai/forecast/shipments` is protected with the existing IFC HMAC request pattern: timestamp, unique nonce, method, path, exact body, shared secret, and correlation ID. Signatures expire after five minutes, nonces are replay checked, and request bodies are bounded to 64 KiB. Laravel uses TLS verification from existing configuration, bounded connect/read timeouts, and safe logs containing correlation IDs rather than business payloads.

Inference is a short deterministic calculation, not a long-running job. It has no business-record write capability. Long-running extraction, report generation, and model training should use authenticated queue jobs before implementation. The current nonce cache is process-local; a multi-worker deployment should move replay state to a shared cache before horizontal scaling.

The pre-existing `/assistant` bearer-token endpoint offers generic freight guidance. It is not a grounded IFC reporting assistant and is not exposed as an Executive data-answer tool. No external model provider is configured or called by this work.

Successful inference runs persist the FastAPI `service_correlation_id` separately from IFC's prediction-run request ID. Inference failures return a sanitized service-unavailable response and are not recorded as prediction results.
