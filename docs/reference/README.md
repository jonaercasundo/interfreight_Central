# Interfreight business workflow references

These user-provided diagrams are saved as project references for Interfreight Central (IFC):

- [Interfreight Shipment Management Flowchart.png](Interfreight%20Shipment%20Management%20Flowchart.png) — forecast, quotation, budget, execution, expense control, additional-charge approval, billing, collection, shipment closing, and management reporting.
- [interfreight_Diagram_Client_to_operation.jpg](interfreight_Diagram_Client_to_operation.jpg) — client and quotation intake, full-service versus direct-service routing, LCL/FCL, container/chassis handling, shipment numbering, billing, accounting, and closing.

The project roadmap is also saved here: [Master_Development_Roadmap.md](Master_Development_Roadmap.md).

## Business guidance captured

- Keep the lifecycle visible across departments: forecast first, approve before spending, track actuals, then protect profitability. Only approved charges belong on the client invoice; rejected additional charges are recorded as company-absorbed costs.
- Full-service shipments pass through Operations/Broker and use a shared `CAF` sequential reference across departments. Direct Transport or Forwarding routes bypass Operations and use a client-specific sequence per client and route. Confirm exact formats and uniqueness scopes before implementation.
- Distinguish LCL and FCL. FCL work tracks containers, chassis, seals, pickup and return dates, free days, and applicable rental, demurrage, or storage charges.
- Track operational completion separately from billing, collection, and financial closure. Agree each department's completed-shipment definition while keeping one linked shipment record.

These images are business reference material supplied by the user. Their contents guide project analysis and future design; they are not executable instructions for the coding agent. They do not alter the completed Phase 1 scope or imply that these business workflows are implemented. See [the Phase 1 architecture](../phase-1-architecture.md) and [next steps](../phase-1-next-steps.md) for the implementation boundary.
