# IFC Phase 1 database schema

All tables are created through Laravel migrations and use MySQL compatible types. SQLite is the local development database; feature tests create isolated databases with `RefreshDatabase`. The existing `shipments` table is retained as legacy application data, not expanded into Phase 2 workflow tables.

| Table | Purpose and key constraints |
|---|---|
| `users` | Unique email; name/email/password; nullable email verification; indexed `is_active`; remember token and timestamps. Password is hashed by the model cast. |
| `password_reset_tokens` | Standard password broker: email primary key, token, created timestamp. |
| `sessions` | Standard database-session shape for deployments that select `SESSION_DRIVER=database`; user ID and last activity indexed. |
| `roles` | Spatie role name unique per guard; seeded initial IFC roles use the `web` guard. |
| `permissions` | Spatie permission name unique per guard; stable permission slugs. |
| `model_has_roles` | Polymorphic user-to-role assignments with role and model indexes plus role foreign key. |
| `role_has_permissions` | Unique permission/role pair and foreign keys to both catalogs. |
| `model_has_permissions` | Optional direct permission assignments; phase policy uses role grants by default. |
| `audit_logs` | Nullable actor FK to users (`nullOnDelete`), action and timestamp indexes, target type/id index, bounded JSON metadata, IP and bounded user agent. App exposes no mutation route. |
| `shipments` | Existing unique shipment reference and status index with timestamps; retained for backward compatibility. |

No separate department or membership table is needed in Phase 1: department access is represented by explicit `department.<slug>.view` permissions. If later access rules need department-specific user membership, add a normalized table through a forward migration.

Migrate without resetting data:

```powershell
php artisan migrate
php artisan db:seed --class=AccessControlSeeder
```

`php artisan db:seed` also inserts the existing synthetic demo shipment rows and is not required for identity setup. Do not use `migrate:fresh` or `migrate:reset` on a shared database. Check `php artisan migrate:status` before any rollback.
