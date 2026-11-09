# ADR-0011 — Deployment, versioning, and rollback

- Status: Proposed
- Date: 2026-11-09
- Deciders: Architect + human release approver

## Context

Each customer runs their own server. Upgrades must be safe, reversible, and executable by a human without vendor access.

## Decision

1. **Artifacts:** versioned Docker images (`api`, `web`) and a Compose file; PostgreSQL and Redis as services. No cloud-provider-specific dependencies.
2. **Versioning:** semantic version tags (`vMAJOR.MINOR.PATCH`); `/health` reports version + migration status.
3. **Migrations:** forward-only in production, ordered, tested on a copy of production-like data. Each release documents rollback (down migration or restore-from-backup if irreversible).
4. **Release sequence:** backup → maintenance mode → pull images → migrate → clear/rebuild caches → health check → smoke test → exit maintenance. Abort on failure with documented rollback.
5. **Backup/restore:** encrypted daily DB backup + uploaded files; restore rehearsal is part of release acceptance.
6. **Secrets:** provided via environment/secret store; never baked into images or committed.
7. **Observability:** health/readiness endpoints, structured logs, failed-job dashboard.
8. **Human approval:** no production deployment without explicit approval; no force push; no direct commits to `main`.
9. **Support:** release notes list schema changes, breaking API changes, known limitations, and rollback steps.

## Consequences

- Predictable, auditable upgrades.
- Irreversible migrations require a backup gate and explicit sign-off.
