# IFC Phase 1 handover and next steps

## Local setup

Follow the root README in PowerShell. After installing dependencies, migrate and seed only the role catalog:

```powershell
php artisan migrate
php artisan db:seed --class=AccessControlSeeder
php artisan ifc:admin
```

The first admin command is interactive. The user chooses the initial login email and password; no static credentials are supplied. Configure SMTP before relying on admin-created password setup links or user-requested password resets.

Run services in separate shells:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
npm run dev -- --host=127.0.0.1
```

```powershell
Set-Location .\python-ai
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001
```

Open `http://127.0.0.1:8000/login`. The dashboard and integration status page show the signed protected FastAPI check. `/health` remains at `http://127.0.0.1:8001/health`.

## Before production

1. Review uncommitted work carefully; this workspace has no Git history. Initialize version control only after preserving any local secrets and databases outside version control.
2. Verify migrations, unique indexes, FK behavior, and seed idempotency on MySQL 8 staging with a backup and representative data.
3. Configure mail delivery, APP_KEY, session storage, strict CORS origins, HTTPS, secure cookies and managed secrets.
4. Configure the same randomly generated `AI_SERVICE_TOKEN` in the root `.env` and FastAPI process environment, then repeat the dashboard diagnostic.
5. Replace in-memory Python nonce tracking with shared replay storage when running multiple workers/instances.
6. Agree audit retention/access policy and export access with the organization's privacy requirements.
7. Run a browser accessibility and responsive-device review.
8. Use [`docs/reference/README.md`](reference/README.md) and its two saved diagrams as business workflow input when shaping Phase 2. Confirm the canonical shipment numbering formats and uniqueness scopes (shared CAF sequence for full service; client-and-route sequence for direct Transport/Forwarding), LCL/FCL rules, FCL container/chassis/free-day and return-charge behavior, approval and company-absorbed-cost treatment for rejected extra charges, and each department's definition of a completed shipment. Then add the shipment master, forecast, quotation, approval, budget, expense, charge approval, billing, collection and profitability entities after workflow permissions and invariants are approved. These references do not change Phase 1, which contains no implementation of those business workflows.

## Schema rollback guidance

Never reset or roll back a shared or production database without a reviewed backup and explicit operational plan. Use `php artisan migrate:status`; only use `php artisan migrate:rollback --step=1` when the target batch is confirmed to contain only reversible schema changes and no data to retain. Prefer a forward corrective migration once users or audit events exist.
