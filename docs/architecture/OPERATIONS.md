# Operations Guide — Backup, Restore, and Release Gate

Self-hosted Tourism ERP — one installation per customer. This document covers
backup, restore, and the release/upgrade process for production installations.

## 1. Backup Strategy

### Database (PostgreSQL)

Take encrypted logical backups daily:

```bash
# Full schema + data dump
docker compose exec -T db pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  | gpg --symmetric --cipher-algo AES256 \
  > "backups/tourism_erp-$(date +%Y%m%d-%H%M%S).sql.gz.gpg"

# Verify the backup can be restored
gpg --decrypt "backups/latest.sql.gz.gpg" \
  | gunzip \
  | docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" --dry-run
```

### Attachments and Uploads

If the application stores uploaded files (future feature), back up the
`storage/app` directory:

```bash
tar -czf "backups/storage-$(date +%Y%m%d-%H%M%S).tar.gz" -C backend storage/app
```

### Configuration

Back up `.env` and any custom nginx/supervisor configs:

```bash
cp .env "backups/env-$(date +%Y%m%d).bak"
cp deploy/nginx/backend.conf "backups/nginx-$(date +%Y%m%d).bak"
```

### Retention

- Daily backups: retain 7 days
- Weekly backups: retain 4 weeks
- Monthly backups: retain 3 months
- Test restores: perform a restore drill monthly

## 2. Restore Procedure

### 1. Stop the application

```bash
docker compose stop backend api web
```

### 2. Restore the database

```bash
# Decompress and decrypt
gpg --decrypt "backups/tourism_erp-YYYYMMDD-HHMMSS.sql.gz.gpg" \
  | gunzip \
  | docker compose exec -T db psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    --clean --if-exists --create
```

### 3. Restore uploads (if applicable)

```bash
tar -xzf "backups/storage-YYYYMMDD-HHMMSS.tar.gz" -C backend
```

### 4. Restart and verify

```bash
docker compose start backend api web
docker compose logs -f backend
curl -f http://localhost:8000/api/v1/ready
```

## 3. Release/Upgrade Gate

### Pre-release checklist

- [ ] Full database backup completed and verified
- [ ] Configuration backup (.env, nginx) completed
- [ ] Release notes reviewed with customer
- [ ] Maintenance window communicated
- [ ] Rollback plan documented (previous image tag + backup location)

### Release steps

```bash
# 1. Enable maintenance mode
docker compose exec backend php artisan down --message="Maintenance" --retry=60

# 2. Pull new images
docker compose pull

# 3. Run migrations (forward-only, tested on staging copy)
docker compose exec backend php artisan migrate --force

# 4. Clear and rebuild caches
docker compose exec backend php artisan optimize:clear
docker compose exec backend php artisan config:cache
docker compose exec backend php artisan route:cache
docker compose exec backend php artisan view:cache

# 5. Restart containers
docker compose up -d --build backend api web

# 6. Health check
curl -f http://localhost:8000/api/v1/ready

# 7. Smoke test: login, create a booking, post a journal entry
curl -f http://localhost:8000/api/v1/health

# 8. Disable maintenance mode
docker compose exec backend php artisan up
```

### Rollback procedure

If any step fails:

```bash
# 1. Restore previous image tag
docker compose up -d --build backend api web  # with previous tag

# 2. Restore database from pre-upgrade backup
# (see Restore Procedure above)

# 3. Disable maintenance mode if still active
docker compose exec backend php artisan up
```

## 4. Health Checks

- `GET /api/v1/health` — public, reports app/database/cache/Redis/scheduler status
- `GET /api/v1/ready` — readiness probe, returns 503 when database is unreachable
- Docker healthchecks on `db`, `redis`, and `api` containers

## 5. Production Data Rules

- **Never** run `migrate:fresh` on production data.
- **Never** delete or rewrite posted journal entries directly.
- **Never** insert production secrets or real customer data into CI.
- **Never** auto-post unapproved accounting decisions (see `decisions-needed.md`).
- **Always** backup before running migrations.

## 6. Release Artifacts

Each release must produce:

1. Docker images tagged with semver (`vMAJOR.MINOR.PATCH`)
2. Release notes describing database migrations and configuration changes
3. Backup verification log from the staging restore drill
4. Rollback plan with previous image tag

## 7. RPO/RTO Targets

| Metric | Target | Notes |
|--------|--------|-------|
| RPO (Recovery Point Objective) | 24 hours | Daily backup schedule |
| RTO (Recovery Time Objective) | 4 hours | Includes restore + verification |

These are targets, not guarantees. Test restores regularly to validate.
