# Coolify deployment: Production and 10-minute disposable demo

This repository supports **two completely separate Coolify Docker Compose resources**.
Do not reuse project/network/volumes/credentials between them. Configure the
public HTTPS domain in Coolify for the `web` service on **container port 80**.
Neither PostgreSQL, Redis, backend nor API should have public ports.

## 1. Persistent production

1. In Coolify create a **Docker Compose** deployment from
   `cgdmohamed/click-tourism`, compose file `docker-compose.coolify.yml`.
2. Configure variables `APP_KEY`, `APP_URL`, `POSTGRES_PASSWORD`.
   Optional: `POSTGRES_DB`, `POSTGRES_USER`. Use long unique secrets.
   Generate an APP_KEY locally with
   `php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`
   (never commit the value). Set `APP_URL=https://erp.example.com`.
3. Attach your domain to **web:80**; enable HTTPS. Turn off public ports for
   all other containers.
4. Deploy and verify `https://erp.example.com/api/v1/ready`.
5. Production has persistent `production_pgdata`, `production_redis` and
   `production_uploads` volumes. Back up and test restores before onboarding.
6. Open the Coolify terminal for service `backend` and run once:
   `php artisan erp:install --company-name="Your Company" --base-currency=EGP --fiscal-start=01-01 --timezone=Africa/Cairo --admin-email=admin@your-domain.example`
   and type a unique administrator password (12+ characters) at the masked
   prompt. This creates the company profile, chart of accounts, roles, fiscal
   calendar and the first administrator, but **never** sample customer records or
   demo login accounts, and there is no default password: without a password the
   command fails. It runs once; a second run is refused. (`setup:admin` still
   works as a deprecated alias.) Do NOT enable `SEED_DEMO` on production.
   Afterwards run `php artisan erp:verify-credentials` and
   `php artisan erp:verify-ledger`. Full procedure and the release gate:
   [`clean-install-acceptance.md`](clean-install-acceptance.md),
   [`release-checklist.md`](release-checklist.md).
7. Review the unresolved accountant sign-offs before live financial posting.

## 2. Disposable public demo (separate project!)

1. Create another independent **Docker Compose** project using
   `docker-compose.demo.coolify.yml` (not an override of production).
2. Configure `DEMO_DB_PASSWORD`, `DEMO_APP_KEY`, `DEMO_URL`, optionally
   `DEMO_PASSWORD` for the seeded demonstration accounts.
   Set `DEMO_URL=https://demo.example.com`. The demo key and password must
   not equal any production secret. The provided default demo password is
   `password`; use a custom throwaway password before making it public.
3. Map only `web:80` to the demo HTTPS domain in Coolify. Do NOT mount any
   production volumes or use the production project network.
4. Seeded accounts include `accountant@example.com`, `sales@example.com`,
   `viewer@example.com`, `admin@example.com`, all with `DEMO_PASSWORD`.
   Share a demo account only; NEVER provide a real user's credentials.
5. The initial backend boot migrates/seeds the separate demo database.
   Every 600 seconds the backend puts the demo into maintenance mode,
   runs `php artisan migrate:fresh --seed --force`, clears its **temporary**
   uploaded files and cache, records a new generation, then reopens access.
6. The banner on **login and every page** warns visitors, displays a countdown,
   and refreshes after resets; users must log in again because demo sessions
   and Sanctum tokens are reset.
7. Demo PostgreSQL runs on tmpfs, Redis has snapshots and AOF disabled, and
   Laravel upload storage is a shared memory-only tmpfs volume (backend + API)
   and application logs are disabled in the demo. Runtime web/proxy access
   logs may still exist on the server and must have separate retention rules. The demo cannot persist its
   application database or uploads through container restarts.

**Tax in the demo:** the baseline is rebuilt by `migrate:fresh --seed` at demo container start (`docker/entrypoint.sh`), and `DemoDataSeeder` sets `tax.enabled=true`, `tax.default_rate=14`, `tax.inclusive=false`. An already-running demo keeps its old saved baseline (with the two legacy tax profiles) until the demo container is redeployed/restarted so the baseline is regenerated; `demo-restore-db.sh` only restores that dump. Do not edit production data.

**Safety constraints:** The reset script refuses execution unless
`DEMO_MODE=true`, `APP_ENV=demo`, `DB_HOST=demo-db`,
`DB_DATABASE=demo_erp`, and the interval is exactly 600 seconds.
The production compose forces `DEMO_MODE=false` and `SEED_DEMO=false`.
Do not use demo with real customer data, bank details, API secrets, or other
confidential information. Visitors share the temporary demo database, so
changes may be visible to other visitors before reset. Container/proxy access
logs and Coolify infrastructure logs may still retain connection metadata;
configure log retention and privacy controls separately. This is **no
permanent storage of application data**, not a guarantee of zero telemetry.

Disable outbound messaging, scheduled accounting tasks, real payment/gateway
credentials and externally connected services in demo. The demo compose uses
`MAIL_MAILER=array` and no worker/scheduler, and keeps Meta/WhatsApp keys empty.

## 3. Verify on the deployed instance

- `GET /api/v1/demo/status` on production must report
  `enabled: false`. On demo it must report `enabled: true`, a
  `generation` identifier and `next_reset_at`.
- Check demo banner and countdown on the login page, desktop, and mobile.
- Log in to demo, create a temporary customer, and record its ID.
- Check it exists before the interval elapses. Observe maintenance mode
  briefly during the reset.
- After the 10-minute reset, the temporary customer must be absent and seeded
  demo data restored. Existing tokens must require fresh authentication.
- Restart the **demo-db** container in a disposable environment and verify no
  previous customer data survives. Never perform this against production.
- Ensure production databases, uploaded files and users remain unchanged.
- Test backup/restore, health checks and reverse-proxy HTTPS separately.

A GitHub Actions success is not a substitute for a real Coolify deployment
and reset drill.

## Report exports and scheduled report e-mail (ADR-0025, ADR-0026)

- **Background exports** run on the queue worker already in this compose (`queue:work`); without a worker a job stays `queued`. Files are written to `EXPORTS_DISK` (default `local`) and deleted after `EXPORTS_TTL_DAYS` (7) by `exports:prune`. Optional: `EXPORTS_ASYNC_MAX_ROWS` (200000), `EXPORTS_TIMEOUT` (600).
- **Scheduled report e-mail** needs the scheduler (already in this compose: `schedule:work`) and a real mailer: set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` in the environment (secrets in Coolify, never in the repository). While `MAIL_MAILER` is `log`, `array` or `null` the Reports screen warns and nothing is sent. Schedules run at 06:00 server time as the user who created them.
