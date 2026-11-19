# IFC Phase 1 security notes

## Authentication and sessions

- Laravel session guard, CSRF middleware, session ID regeneration after login, invalidation and token regeneration at logout.
- Login and reset submission routes are throttled. Account status is checked on every authenticated web/API request.
- Passwords use Laravel's adaptive hasher through a model cast. Password change requires the current password and a 12-character minimum; reset links use Laravel's expiring database broker.
- User creation generates a random unknown password and sends the normal password setup link. Mail must be configured to deliver links. No default or public registration account exists.
- `php artisan ifc:admin` bootstraps one first Administrator interactively; it requires an interactive console, prompts without echoing the secret, and refuses when an active Administrator already exists.
- Last active Administrator deactivation and demotion are blocked.
- Password reset and admin setup emails use the configured SMTP mailer; reset links are never emitted into application logs by the default mail transport.

## Authorization and audit

Spatie Laravel Permission is pinned in Composer at a Laravel 13 compatible version. Server routes and mutation handlers check defined permissions; menu visibility is only a presentation aid. User role assignment is separately authorized, elevated permission grants are rejected for non-Administrator roles, and business approvals remain unimplemented pending explicit policy.

Audit events include actor, action, target, time, request IP/user-agent, and bounded metadata. Sensitive metadata keys are filtered, strings/arrays are length-limited, login failure records omit submitted credentials, and no endpoint can edit or delete audit events. IP and user agent are personal data: set access, retention and deletion policy for the deployment jurisdiction before go-live.

## Laravel–FastAPI

- Existing `/health` remains unauthenticated for basic liveness only.
- `/internal/diagnostic` requires a SHA-256 HMAC over timestamp, unique nonce, method, path and body. FastAPI validates the five-minute clock window, constant-time signature, and replayed nonces; Laravel adds a correlation ID.
- Nonce replay storage is in-process and bounded. A multi-worker or multi-instance production deployment must use shared Redis/database replay storage or a gateway with equivalent nonce enforcement.
- Existing `/assistant` uses a server-side bearer credential and strict request/response validation. It is an idempotent text endpoint; never put its key in Vue/browser config.
- Authentication failures on the protected diagnostic are written to audit history with a request ID and status only; credentials and upstream response bodies are omitted.
- Laravel HTTP clients use short connect/read timeouts. TLS verification defaults on, and production rejects HTTP URLs and any attempt to disable TLS verification. Restrict FastAPI to the internal network at deployment.
- Python does not mutate Laravel business records.

## Configuration and deployment

Environment variable names (never commit secret values):

`APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN`, `CORS_ALLOWED_ORIGINS`, `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `AI_SERVICE_URL`, `AI_SERVICE_TOKEN`, `AI_SERVICE_CONNECT_TIMEOUT`, `AI_SERVICE_TIMEOUT`, `AI_SERVICE_VERIFY_TLS`.

Use a unique generated `APP_KEY`, private shared service token, production `APP_DEBUG=false`, HTTPS, secure session cookies, verified TLS, restricted CORS origin list, least-privilege database credentials, private service networking, and a managed secrets store. Set `SESSION_DRIVER=database` or a shared Redis session store for multi-node deployments. Configure Redis cache/queues only when the deployment actually provides Redis. The current Docker Compose file is development oriented and is not a production deployment recipe.

Laravel also sends `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and a restrictive `Permissions-Policy`; HSTS is sent on HTTPS requests.
