# Phase 5 Shipment Status Rules

The master status is independent from customs, transport trips, bookings, deliveries and POD. Supported master states are `open`, `in_progress`, `on_hold`, `operationally_completed`, `cancelled`, and `financially_closed` (financial close is not currently exposed as a workflow).

Authorized shipment editors advance open work to in progress, place work on hold, resume it, and mark operational completion. Every master transition requires the current lock version and a reason; stale writes return conflict. Cancellation is a separate permission and requires a reason. Cancelled and financially closed records cannot be edited.

Trip statuses follow the transport transition graph enforced by the controller. Customs and booking states have their own domain values. Their changes never implicitly update the master state. Every change is recorded in status history or the shared timeline.
