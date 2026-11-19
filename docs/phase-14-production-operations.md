# Phase 14 production operations template

This is a preparation checklist, not authorization to deploy. It has not been exercised against production infrastructure. Never point the MySQL developer profile or restore-drill scripts at a production database.

## Before a release

1. Attach Finance/legal approval for invoice setup and the separately approved revenue, accrual and FX policy. Until then keep `IFC_BILLING_LEGAL_ISSUANCE_VERIFIED=false` and `IFC_FINANCE_POLICY_STATUS=unconfigured`.
2. Provision a dedicated MySQL 8.4 database, Redis instance, external TLS termination/certificate renewal, private-document storage, encrypted backups, monitoring and a secret store. The production Compose file is a single-host template, not a high-availability or disaster-recovery design.
3. Provide `APP_KEY`, database/Redis credentials, AI service token, AI replay Redis URL, canonical HTTPS URL, web TLS directory, and AI TLS directory through the deployment secret manager. AI TLS files must include `fullchain.pem`, `privkey.pem`, and `ca.pem`; the leaf certificate must be valid for Docker DNS name `ai`, and `ca.pem` must be trusted by Laravel's HTTP client.
4. Build and scan immutable PHP-FPM, Nginx and FastAPI images. Confirm no `.env`, database dump, private upload or development secret entered the image.
5. Take and verify a pre-release encrypted database and private-document backup. Review every pending migration, its locking/availability characteristics and rollback plan before approval.

## Controlled start and migration

The production Compose file has no automatic migration command. After external prechecks and release approval, start the database and Redis, then run the migration as a separately reviewed one-off task:

```powershell
docker compose -f docker-compose.production.yml up -d mysql redis
docker compose -f docker-compose.production.yml run --rm --no-deps app php artisan migrate --force
docker compose -f docker-compose.production.yml up -d app ai queue nginx
docker compose -f docker-compose.production.yml ps
```

These commands have not been run. A command appearing here is not evidence that the schema, TLS, credentials, backups or application are production-ready.

## Rollback and recovery

- Keep the prior immutable image tags and deployment configuration. Roll back application images/configuration only after checking schema compatibility; do not run down-migrations blindly.
- If database rollback is required, stop writes and use an approved isolated recovery plan based on a verified backup. The restore script only accepts an empty target with a name ending `_restore_drill`; it never restores over an existing table set.
- Restore private documents to a separate verification location and confirm representative database document paths/hashes before any cutover.
- Record actual restore duration, data cutoff, record counts, foreign-key/integrity results, private-document counts and authorized reviewer. Until measured, RTO and RPO are unknown.

## Operational checks still required

Validate Nginx HTTPS `/up`, Laravel database/cache/session operation, FastAPI authenticated signed diagnostics over verified HTTPS, Redis replay rejection across workers, queue retries/failed-job alerting, private upload/download, backup age/key custody, migration completion, logs/alerts and rollback on an isolated staging environment. This repository template does not configure external monitoring, certificate renewal, host hardening, backup replication, or a multi-node failover strategy.
