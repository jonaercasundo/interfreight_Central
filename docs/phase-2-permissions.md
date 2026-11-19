# IFC Phase 2 — Permission Matrix

Every protected page and API endpoint checks Laravel permissions. Vue navigation and action buttons only reflect server-injected permission names and are not the enforcement boundary. Sales records are also scoped by owner.

| Permission | Sales baseline | Accounting baseline | Administrator |
|---|---:|---:|---:|
| `clients.view` | Yes | No | Yes |
| `clients.create` | Yes | No | Yes |
| `clients.update` | Yes | No | Yes |
| `inquiries.view` | Yes | No | Yes |
| `inquiries.create` | Yes | No | Yes |
| `inquiries.update` | Yes | No | Yes |
| `inquiries.submit` | Yes | No | Yes |
| `inquiries.review` | Yes | No | Yes |
| `inquiries.cancel` | Yes | No | Yes |
| `forecasts.view` | Yes | No | Yes |
| `forecasts.create` | Yes | No | Yes |
| `forecasts.update` | Yes | No | Yes |
| `forecasts.withdraw` | Yes | No | Yes |
| `forecasts.accounting.view` | No | Yes | Yes |

The additive permission migration adds these permissions and grants the Sales, Accounting and Administrator roles the appropriate Phase 2 capabilities when those roles already exist. The idempotent access-control seeder assigns the same permissions when it creates those roles. Existing non-administrator role customizations are preserved by the seeder. It does not grant Sales or Accounting mutation rights outside their module.

Sales representatives can access only clients, inquiries and forecasts with their own `sales_owner_id`. A foreign inquiry/forecast ID returns not found. Accounting endpoints are GET-only and expose only active probable/confirmed forecasts; the browser receives no write control. Finance, Billing, Executive and other department roles receive no Phase 2 grants by default. Administrators have their registered permissions from the existing policy and can use the Sales endpoints, but record ownership remains enforced by the implementation.
