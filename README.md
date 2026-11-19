# InterFreight Central (IFC)

InterFreight Central is the INTERFREIGHT GROUP shipment management platform. The current foundation provides authenticated Laravel 13 / Vue 3 navigation, database driven role permissions, user administration, audit history, and a protected Laravel to FastAPI diagnostic. Existing shipment API and assistant capabilities remain available behind authorization.

## Requirements

- PHP 8.3+, Composer 2, and `pdo_sqlite` for the local default
- Node.js 20+ and npm
- Python 3.13 (the included virtual environment uses Python 3.13)
- MySQL 8+ is the deployment target; SQLite is the local development default

## First run (PowerShell)

Run in the project directory:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
New-Item -ItemType File -Path database\database.sqlite -Force
php artisan migrate
php artisan db:seed --class=AccessControlSeeder
npm install
npm run build
```

Set `AI_SERVICE_TOKEN` in the ignored root `.env` to a long random value. The same value must be provided to FastAPI. Do not commit it or place it in Vite variables. Set SMTP variables before using email based password resets and initial account setup. For MySQL, set `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` before migrating.

Provision the first Administrator through the interactive prompt. The command asks for the email, display name, and password without echoing the password:

```powershell
php artisan ifc:admin
```

There is no public registration or default account password. After setup, open `http://127.0.0.1:8000/login`.

## Run locally

In separate PowerShell windows:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
npm run dev -- --host=127.0.0.1
```

FastAPI (from the `python-ai` directory):

```powershell
$env:AI_SERVICE_TOKEN = (Get-Content ..\.env | Where-Object { $_ -match '^AI_SERVICE_TOKEN=' } | Select-Object -First 1).Split('=',2)[1].Trim('"')
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001
```

The app is at `http://127.0.0.1:8000`, FastAPI health is at `http://127.0.0.1:8001/health`, and the protected diagnostic runs from the authenticated IFC dashboard / Integration Health page. Avoid printing `.env` contents in shared terminals. Alternatively set the token in the current PowerShell session manually before launching Uvicorn.

## Roles and data

Seed the access control catalog with `php artisan db:seed --class=AccessControlSeeder`; rerunning creates missing catalog entries and preserves explicit non-Administrator permission edits. The Administrator role retains every defined permission. `php artisan db:seed` also inserts the legacy sample shipment rows for local demos. Those rows are synthetic and must not be treated as company business records. Phase 1 does not add new shipment forecasting, quotation, expense, billing, or profitability workflows.

Useful endpoints:

- `GET /api/me` returns the signed-in user's roles and permissions.
- `GET /api/dashboard` returns permission scoped account metrics, FastAPI diagnostic status, and recent allowed audit events.
- `GET /api/shipments` retains the existing authorized shipment list API. Shipment writes require `shipments.manage`.
- `POST /api/assistant` retains the authenticated Laravel proxy to the protected FastAPI assistant.

## Checks

```powershell
php artisan test
Set-Location .\python-ai
.\.venv\Scripts\python.exe -m pytest
Set-Location ..
npm run build
vendor\bin\pint --test
```

Further setup, architecture, permissions, schema, security, verification, and Phase 2 boundaries are documented in [`docs/`](docs/phase-1-architecture.md).

## Safe rollback

Take a database backup before production migrations. Roll back only a known migration batch that contains no user or audit records to preserve, using `php artisan migrate:rollback --step=1` after reviewing `php artisan migrate:status`. Never run `migrate:fresh`, `migrate:reset`, or a database reset against a shared or production database. A safer production response to a schema change is usually a forward fix.
