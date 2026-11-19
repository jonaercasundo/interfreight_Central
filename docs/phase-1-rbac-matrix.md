# IFC Phase 1 RBAC matrix

Roles are database records seeded idempotently. `Administrator` is the sole default role with full defined administrative permission. `Executive` has cross-department read visibility, audit visibility, integration status, and executive overview; it receives no default mutation permissions. Department roles receive dashboard/profile access, their own `department.<name>.view`, and the shared shipment read and assistant permissions. Finance, Accounting, and Billing are separate roles.

| Capability | Administrator | Executive | Sales | Operations | Transport | Forwarding | Accounting | Billing | Finance |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Dashboard | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Executive overview | ✓ | ✓ | — | — | — | — | — | — | — |
| View own department workspace | ✓ | ✓ all | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| View users / create / update / deactivate | ✓ | — | — | — | — | — | — | — | — |
| Assign roles | ✓ | — | — | — | — | — | — | — | — |
| Manage roles and permissions | ✓ | — | — | — | — | — | — | — | — |
| View audit | ✓ | ✓ | — | — | — | — | — | — | — |
| Export audit | ✓ | — | — | — | — | — | — | — | — |
| Integration status | ✓ | ✓ | — | — | — | — | — | — | — |
| View existing shipment records | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Mutate existing shipment records | ✓ | — | — | — | — | — | — | — | — |
| Use FastAPI assistant | ✓ | — | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| View / change own profile | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

The exact initial roles are `Administrator`, `Executive`, `Sales`, `Operations`, `Transport`, `Forwarding`, `Accounting`, `Billing`, and `Finance`. Multiple roles can be assigned. The user admin screen checks role assignment separately from user updates. Role permission management rejects defined administrative permissions on non-Administrator roles and prevents an Administrator role from losing its defined permission set. The application also prevents deactivating or demoting the last active Administrator.

The full permission catalog includes `dashboard.view`, `executive.overview.view`, `users.view`, `users.create`, `users.update`, `users.deactivate`, `users.assign-role`, `roles.view`, `roles.manage`, `permissions.view`, `permissions.manage`, `audit.view`, `audit.export`, `integrations.status.view`, `profile.view`, `profile.update`, `assistant.use`, `shipments.view`, `shipments.manage`, and one `department.<slug>.view` permission per department.

Apply or restore the baseline with:

```powershell
php artisan db:seed --class=AccessControlSeeder
```

The seeder creates missing roles/permissions, assigns the initial matrix to newly created roles, and leaves explicit permission edits to existing non-Administrator roles intact. It adds all defined permissions to Administrator. An Administrator can explicitly change role permissions at `/admin/roles`. Grant business approval permissions only after the corresponding workflow and approval policy are implemented.
